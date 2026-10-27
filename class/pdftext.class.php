<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Reads the text of a PDF bank statement, line by line as it is printed, so
 * Reconcile bank accounts can take its transactions. Enough of PDF for
 * statements made by banking software: objects and object streams, Flate
 * compression, fonts with a ToUnicode map (one or two byte codes) or plain
 * WinAnsi text, and the text positioning operators. Scanned statements (a
 * picture of the page) have no text; images, forms and encryption are not read.
 */

/**
 * PDF to lines of text.
 */
class OnboardingPdfText
{
	/** @var string */
	private $data = '';
	/** @var array<int,mixed> Objects by number */
	private $objects = array();
	/** @var array<int,string> Raw streams by object number */
	private $streams = array();
	/** @var array<string,array> Fonts already read, by object number */
	private $fonts = array();

	/**
	 * @param string $data The PDF file's bytes
	 * @return string[][] Pages, each a list of lines; columns on a line are joined by three spaces
	 */
	public static function lines($data)
	{
		$p = new self();
		$p->data = (string) $data;
		if (strpos(substr($p->data, 0, 1024), '%PDF') === false) {
			return array();
		}
		$p->readObjects();
		$out = array();
		foreach ($p->pages() as $page) {
			$out[] = $p->pageLines($page);
		}
		return $out;
	}

	// ---------------------------------------------------------------- objects

	/**
	 * Every "n g obj ... endobj" in the file, and the objects inside object streams.
	 *
	 * @return void
	 */
	private function readObjects()
	{
		if (!preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $this->data, $m, PREG_OFFSET_CAPTURE)) {
			return;
		}
		$skipUntil = 0;
		foreach ($m[0] as $i => $hit) {
			if ($hit[1] < $skipUntil) {
				continue; // Inside the last stream's bytes, not an object.
			}
			$num = (int) $m[1][$i][0];
			$pos = $hit[1] + strlen($hit[0]);
			$value = $this->parseAt($this->data, $pos);
			$this->objects[$num] = $value;
			// A stream follows its dictionary.
			if (is_array($value) && isset($value['__dict']) && preg_match('/\G\s*stream\r?\n/', $this->data, $s, 0, $pos)) {
				$start = $pos + strlen($s[0]);
				// The Length may point to an object later in the file: look for endstream then.
				$len = isset($value['Length']) && !is_array($value['Length']) ? $value['Length'] : null;
				if (is_int($len) && preg_match('/^\s*endstream/', (string) substr($this->data, $start + $len, 30))) {
					$raw = substr($this->data, $start, $len);
				} else {
					$end = strpos($this->data, 'endstream', $start);
					$raw = rtrim(substr($this->data, $start, $end === false ? 0 : $end - $start), "\r\n");
				}
				$this->streams[$num] = $raw;
				$skipUntil = $start + strlen($raw);
			}
		}
		// Objects packed in object streams.
		foreach ($this->objects as $num => $o) {
			if (is_array($o) && isset($o['Type']) && $o['Type'] === '/ObjStm' && isset($this->streams[$num])) {
				$body = $this->decode($o, $this->streams[$num]);
				$n = (int) $this->resolve(isset($o['N']) ? $o['N'] : 0);
				$first = (int) $this->resolve(isset($o['First']) ? $o['First'] : 0);
				$head = preg_split('/\s+/', trim(substr($body, 0, $first)));
				for ($k = 0; $k + 1 < count($head) && $k < 2 * $n; $k += 2) {
					$p = $first + (int) $head[$k + 1];
					if (!isset($this->objects[(int) $head[$k]])) {
						$this->objects[(int) $head[$k]] = $this->parseAt($body, $p);
					}
				}
			}
		}
	}

	/**
	 * @param mixed $v A value or a reference
	 * @return mixed What it points to
	 */
	private function resolve($v)
	{
		for ($i = 0; $i < 20 && is_array($v) && isset($v['__ref']); $i++) {
			$v = isset($this->objects[$v['__ref']]) ? $this->objects[$v['__ref']] : null;
		}
		return $v;
	}

	/**
	 * @param array $dict Stream dictionary
	 * @param string $raw Stream bytes
	 * @return string Decoded, '' for filters not read here
	 */
	private function decode($dict, $raw)
	{
		$filters = isset($dict['Filter']) ? $this->resolve($dict['Filter']) : array();
		$filters = is_array($filters) && !isset($filters['__dict']) ? $filters : array($filters);
		foreach ($filters as $f) {
			if ($f === null || $f === '') {
				continue;
			}
			if ($f === '/FlateDecode' || $f === '/Fl') {
				$out = @gzuncompress($raw);
				if ($out === false) {
					$out = @gzinflate(substr($raw, 2));
				}
				if ($out === false) {
					return '';
				}
				$raw = $out;
			} elseif ($f === '/ASCIIHexDecode' || $f === '/AHx') {
				$raw = (string) @hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', strstr($raw, '>', true) !== false ? strstr($raw, '>', true) : $raw).'');
			} else {
				return '';
			}
		}
		return $raw;
	}

	/**
	 * @param int $num Stream object
	 * @return string Its decoded bytes
	 */
	private function stream($num)
	{
		return isset($this->streams[$num]) && is_array($this->objects[$num]) ? $this->decode($this->objects[$num], $this->streams[$num]) : '';
	}

	// ---------------------------------------------------------------- the object syntax

	/**
	 * Parse one value starting at $pos.
	 *
	 * @param string $s Text
	 * @param int $pos Where; moved past the value
	 * @return mixed Dictionaries are arrays with __dict, references arrays with __ref,
	 *               names strings starting with /, strings arrays with __str
	 */
	private function parseAt($s, &$pos)
	{
		$len = strlen($s);
		$this->skip($s, $pos);
		if ($pos >= $len) {
			return null;
		}
		$c = $s[$pos];
		if ($c === '<' && isset($s[$pos + 1]) && $s[$pos + 1] === '<') {
			$pos += 2;
			$d = array('__dict' => true);
			while (true) {
				$this->skip($s, $pos);
				if ($pos >= $len || ($s[$pos] === '>' && isset($s[$pos + 1]) && $s[$pos + 1] === '>')) {
					$pos += 2;
					return $d;
				}
				$k = $this->parseAt($s, $pos);
				if (!is_string($k) || $k === '' || $k[0] !== '/') {
					return $d;
				}
				$d[substr($k, 1)] = $this->parseAt($s, $pos);
			}
		}
		if ($c === '[') {
			$pos++;
			$a = array();
			while (true) {
				$this->skip($s, $pos);
				if ($pos >= $len || $s[$pos] === ']') {
					$pos++;
					return $a;
				}
				$a[] = $this->parseAt($s, $pos);
			}
		}
		if ($c === '(') {
			return array('__str' => $this->literal($s, $pos));
		}
		if ($c === '<') {
			$end = strpos($s, '>', $pos);
			$hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $pos + 1, $end - $pos - 1));
			$pos = $end + 1;
			return array('__str' => (string) @hex2bin(strlen($hex) % 2 ? $hex.'0' : $hex));
		}
		if ($c === '/') {
			preg_match('/\G\/[^\s\/\[\]<>(){}%]*/', $s, $m, 0, $pos);
			$pos += strlen($m[0]);
			return $m[0];
		}
		if (preg_match('/\G[+-]?(\d+\.?\d*|\.\d+)/', $s, $m, 0, $pos)) {
			// "n g R" is a reference.
			if (preg_match('/\G(\d+)\s+(\d+)\s+R\b/', $s, $r, 0, $pos)) {
				$pos += strlen($r[0]);
				return array('__ref' => (int) $r[1]);
			}
			$pos += strlen($m[0]);
			return strpos($m[0], '.') !== false ? (float) $m[0] : (int) $m[0];
		}
		if (preg_match('/\G[A-Za-z\'"*]+/', $s, $m, 0, $pos)) {
			$pos += strlen($m[0]);
			return array('__op' => $m[0]);
		}
		$pos++;
		return null;
	}

	/**
	 * @param string $s Text
	 * @param int $pos Moved past white space and comments
	 * @return void
	 */
	private function skip($s, &$pos)
	{
		$len = strlen($s);
		while ($pos < $len) {
			$c = $s[$pos];
			if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t" || $c === "\f" || $c === "\0") {
				$pos++;
			} elseif ($c === '%') {
				while ($pos < $len && $s[$pos] !== "\n" && $s[$pos] !== "\r") {
					$pos++;
				}
			} else {
				return;
			}
		}
	}

	/**
	 * A (literal string), with its escapes and nested brackets.
	 *
	 * @param string $s Text
	 * @param int $pos At the "("; moved past the ")"
	 * @return string Bytes
	 */
	private function literal($s, &$pos)
	{
		$len = strlen($s);
		$pos++;
		$depth = 1;
		$out = '';
		$map = array('n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\');
		while ($pos < $len) {
			$c = $s[$pos];
			if ($c === '\\') {
				$n = isset($s[$pos + 1]) ? $s[$pos + 1] : '';
				if (isset($map[$n])) {
					$out .= $map[$n];
					$pos += 2;
				} elseif (preg_match('/\G[0-7]{1,3}/', $s, $m, 0, $pos + 1)) {
					$out .= chr(octdec($m[0]) & 0xFF);
					$pos += 1 + strlen($m[0]);
				} elseif ($n === "\r" || $n === "\n") {
					$pos += ($n === "\r" && isset($s[$pos + 2]) && $s[$pos + 2] === "\n") ? 3 : 2;
				} else {
					$pos++;
				}
				continue;
			}
			if ($c === '(') {
				$depth++;
			} elseif ($c === ')') {
				$depth--;
				if ($depth === 0) {
					$pos++;
					return $out;
				}
			}
			$out .= $c;
			$pos++;
		}
		return $out;
	}

	// ---------------------------------------------------------------- pages and fonts

	/**
	 * @return array[] Page dictionaries in order, each with its inherited Resources
	 */
	private function pages()
	{
		$root = null;
		foreach ($this->objects as $o) {
			if (is_array($o) && isset($o['Type']) && $o['Type'] === '/Catalog') {
				$root = $o;
				break;
			}
		}
		$out = array();
		$walk = function ($node, $res, $depth) use (&$walk, &$out) {
			$node = $this->resolve($node);
			if (!is_array($node) || $depth > 30) {
				return;
			}
			if (isset($node['Resources'])) {
				$res = $node['Resources'];
			}
			if (isset($node['Kids'])) {
				foreach ((array) $this->resolve($node['Kids']) as $kid) {
					$walk($kid, $res, $depth + 1);
				}
			} elseif (isset($node['Contents'])) {
				$node['Resources'] = $res;
				$out[] = $node;
			}
		};
		if ($root && isset($root['Pages'])) {
			$walk($root['Pages'], null, 0);
		}
		if (!$out) {
			foreach ($this->objects as $o) {
				if (is_array($o) && isset($o['Type']) && $o['Type'] === '/Page') {
					$out[] = $o;
				}
			}
		}
		return $out;
	}

	/**
	 * How to turn a font's codes into text.
	 *
	 * @param mixed $ref The font
	 * @return array{bytes:int,map:array<string,string>}
	 */
	private function font($ref)
	{
		$key = is_array($ref) && isset($ref['__ref']) ? 'r'.$ref['__ref'] : md5(serialize($ref));
		if (isset($this->fonts[$key])) {
			return $this->fonts[$key];
		}
		$f = $this->resolve($ref);
		$font = array('bytes' => (is_array($f) && isset($f['Subtype']) && $f['Subtype'] === '/Type0') ? 2 : 1, 'map' => array());
		if (is_array($f) && isset($f['ToUnicode']) && is_array($f['ToUnicode']) && isset($f['ToUnicode']['__ref'])) {
			$cmap = $this->stream($f['ToUnicode']['__ref']);
			if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cmap, $m)) {
				$font['bytes'] = max(1, (int) (strlen($m[1]) / 2));
			}
			$font['map'] = self::cmap($cmap);
		}
		return $this->fonts[$key] = $font;
	}

	/**
	 * @param string $cmap A ToUnicode CMap
	 * @return array<string,string> Code bytes => UTF-8 text
	 */
	public static function cmap($cmap)
	{
		$map = array();
		$utf = function ($hex) {
			$b = (string) @hex2bin(strlen($hex) % 2 ? '0'.$hex : $hex);
			$t = @iconv('UTF-16BE', 'UTF-8//IGNORE', $b);
			return $t === false ? '' : $t;
		};
		if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
			foreach ($blocks[1] as $b) {
				preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $b, $pairs, PREG_SET_ORDER);
				foreach ($pairs as $p) {
					$map[(string) hex2bin(strlen($p[1]) % 2 ? '0'.$p[1] : $p[1])] = $utf($p[2]);
				}
			}
		}
		if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
			foreach ($blocks[1] as $b) {
				preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $b, $ranges, PREG_SET_ORDER);
				foreach ($ranges as $r) {
					$lo = hexdec($r[1]);
					$hi = hexdec($r[2]);
					$w = strlen($r[1]);
					if ($hi - $lo > 65535) {
						continue;
					}
					if ($r[3][0] === '[') {
						preg_match_all('/<([0-9A-Fa-f]*)>/', $r[3], $list);
						foreach ($list[1] as $i => $hex) {
							$map[(string) hex2bin(str_pad(dechex($lo + $i), $w, '0', STR_PAD_LEFT))] = $utf($hex);
						}
					} else {
						$base = trim($r[3], '<>');
						$start = hexdec($base);
						for ($c = $lo; $c <= $hi; $c++) {
							$map[(string) hex2bin(str_pad(dechex($c), $w, '0', STR_PAD_LEFT))] = $utf(str_pad(dechex($start + $c - $lo), max(4, strlen($base)), '0', STR_PAD_LEFT));
						}
					}
				}
			}
		}
		return $map;
	}

	/**
	 * @param string $bytes Shown string
	 * @param array{bytes:int,map:array<string,string>} $font Font
	 * @return string UTF-8 text
	 */
	private static function text($bytes, $font)
	{
		if (!$font['map']) {
			if ($font['bytes'] == 2) {
				return '';
			}
			$t = @iconv('Windows-1252', 'UTF-8//IGNORE', $bytes);
			return $t === false ? '' : $t;
		}
		$out = '';
		$n = $font['bytes'];
		for ($i = 0; $i + $n <= strlen($bytes); $i += $n) {
			$code = substr($bytes, $i, $n);
			$out .= isset($font['map'][$code]) ? $font['map'][$code] : '';
		}
		return $out;
	}

	// ---------------------------------------------------------------- the page's text

	/**
	 * @param array $page Page dictionary
	 * @return string[] Its lines, top to bottom
	 */
	private function pageLines($page)
	{
		$content = '';
		$c = $page['Contents'];
		$r = $this->resolve($c);
		// One stream, or an array of them (written out or by reference).
		$list = is_array($r) && isset($r['__dict']) ? array($c) : (is_array($r) ? $r : array());
		foreach ($list as $part) {
			if (is_array($part) && isset($part['__ref'])) {
				$content .= $this->stream($part['__ref'])."\n";
			}
		}
		$res = $this->resolve(isset($page['Resources']) ? $page['Resources'] : null);
		$fonts = is_array($res) && isset($res['Font']) ? $this->resolve($res['Font']) : array();
		$runs = $this->runs($content, is_array($fonts) ? $fonts : array());
		// Rows by height on the page (2 points of slack), left to right.
		usort($runs, function ($a, $b) {
			return $b[1] <=> $a[1] ?: $a[0] <=> $b[0];
		});
		$rows = array();
		foreach ($runs as $r) {
			$last = count($rows) - 1;
			if ($last >= 0 && abs($rows[$last]['y'] - $r[1]) <= 2) {
				$rows[$last]['runs'][] = $r;
			} else {
				$rows[] = array('y' => $r[1], 'runs' => array($r));
			}
		}
		$out = array();
		foreach ($rows as $row) {
			usort($row['runs'], function ($a, $b) {
				return $a[0] <=> $b[0];
			});
			$parts = array();
			foreach ($row['runs'] as $r) {
				if (trim($r[2]) !== '') {
					$parts[] = trim(preg_replace('/\s+/u', ' ', $r[2]));
				}
			}
			if ($parts) {
				$out[] = implode('   ', $parts);
			}
		}
		return $out;
	}

	/**
	 * Walk a content stream: where each piece of text is shown.
	 *
	 * @param string $s Content stream
	 * @param array $fonts Font resources by name
	 * @return array<int,array{0:float,1:float,2:string}> x, y, text
	 */
	private function runs($s, $fonts)
	{
		$runs = array();
		$ops = array();
		$pos = 0;
		$len = strlen($s);
		$ctm = array(1, 0, 0, 1, 0, 0);
		$stack = array();
		$tm = $tlm = array(1, 0, 0, 1, 0, 0);
		$leading = 0.0;
		$font = array('bytes' => 1, 'map' => array());
		$moved = true;
		$mul = function ($a, $b) {
			return array(
				$a[0] * $b[0] + $a[1] * $b[2], $a[0] * $b[1] + $a[1] * $b[3],
				$a[2] * $b[0] + $a[3] * $b[2], $a[2] * $b[1] + $a[3] * $b[3],
				$a[4] * $b[0] + $a[5] * $b[2] + $b[4], $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
			);
		};
		$show = function ($text) use (&$runs, &$tm, &$ctm, &$moved, $mul) {
			if ($text === '') {
				return;
			}
			$m = $mul($tm, $ctm);
			$last = count($runs) - 1;
			if (!$moved && $last >= 0) {
				$runs[$last][2] .= $text; // Shown right after the last, with no move between.
			} else {
				$runs[] = array($m[4], $m[5], $text);
			}
			$moved = false;
		};
		$num = function ($v) {
			return is_int($v) || is_float($v) ? (float) $v : 0.0;
		};
		while ($pos < $len) {
			$v = $this->parseAt($s, $pos);
			if ($v === null && $pos >= $len) {
				break;
			}
			if (!is_array($v) || !isset($v['__op'])) {
				$ops[] = $v;
				if (count($ops) > 64) {
					array_shift($ops);
				}
				continue;
			}
			$op = $v['__op'];
			$n = count($ops);
			switch ($op) {
				case 'BI':
					// An inline image: skip to its end.
					$end = strpos($s, 'EI', $pos);
					$pos = $end === false ? $len : $end + 2;
					break;
				case 'q':
					$stack[] = $ctm;
					break;
				case 'Q':
					$ctm = $stack ? array_pop($stack) : $ctm;
					break;
				case 'cm':
					if ($n >= 6) {
						$ctm = $mul(array_map($num, array_slice($ops, -6)), $ctm);
					}
					break;
				case 'BT':
					$tm = $tlm = array(1, 0, 0, 1, 0, 0);
					$moved = true;
					break;
				case 'Tf':
					if ($n >= 2 && is_string($ops[$n - 2]) && isset($fonts[substr($ops[$n - 2], 1)])) {
						$font = $this->font($fonts[substr($ops[$n - 2], 1)]);
					}
					break;
				case 'TL':
					$leading = $n ? $num($ops[$n - 1]) : $leading;
					break;
				case 'Td':
				case 'TD':
					if ($n >= 2) {
						$tx = $num($ops[$n - 2]);
						$ty = $num($ops[$n - 1]);
						if ($op === 'TD') {
							$leading = -$ty;
						}
						$tm = $tlm = $mul(array(1, 0, 0, 1, $tx, $ty), $tlm);
						$moved = true;
					}
					break;
				case 'Tm':
					if ($n >= 6) {
						$tm = $tlm = array_map($num, array_slice($ops, -6));
						$moved = true;
					}
					break;
				case 'T*':
					$tm = $tlm = $mul(array(1, 0, 0, 1, 0, -$leading), $tlm);
					$moved = true;
					break;
				case 'Tj':
				case "'":
				case '"':
					if ($op !== 'Tj') {
						$tm = $tlm = $mul(array(1, 0, 0, 1, 0, -$leading), $tlm);
						$moved = true;
					}
					if ($n && is_array($ops[$n - 1]) && isset($ops[$n - 1]['__str'])) {
						$show(self::text($ops[$n - 1]['__str'], $font));
					}
					break;
				case 'TJ':
					if ($n && is_array($ops[$n - 1]) && !isset($ops[$n - 1]['__str'])) {
						$t = '';
						foreach ($ops[$n - 1] as $part) {
							if (is_array($part) && isset($part['__str'])) {
								$t .= self::text($part['__str'], $font);
							} elseif ((is_int($part) || is_float($part)) && $part < -200) {
								$t .= ' '; // A wide gap is a space.
							}
						}
						$show($t);
					}
					break;
			}
			$ops = array();
		}
		return $runs;
	}
}
