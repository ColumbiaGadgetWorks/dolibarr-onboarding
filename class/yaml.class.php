<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * A small YAML reader for the CGW home page layout: maps, lists, nested blocks
 * by indentation, "key: value", "- item", inline lists [a, b], quoted strings
 * and # comments. Enough for a hand-edited layout; anchors, multi-line
 * strings and the rest of YAML are not supported.
 */

/**
 * Read a YAML subset into arrays.
 */
class OnboardingYaml
{
	/** @var array<int,array{n:int,indent:int,text:string}> */
	private $lines = array();
	/** @var int */
	private $pos = 0;

	/**
	 * @param string $text YAML
	 * @return array Parsed
	 * @throws InvalidArgumentException With the line number when something cannot be read
	 */
	public static function parse($text)
	{
		$y = new self();
		foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $i => $raw) {
			if (strpos($raw, "\t") !== false && preg_match('/^\s*\t/', $raw)) {
				throw new InvalidArgumentException('Line '.($i + 1).': use spaces, not tabs, to indent.');
			}
			$line = self::stripComment($raw);
			if (trim($line) === '') {
				continue;
			}
			$y->lines[] = array('n' => $i + 1, 'indent' => strlen($line) - strlen(ltrim($line, ' ')), 'text' => trim($line));
		}
		if (!$y->lines) {
			return array();
		}
		$out = $y->block($y->lines[0]['indent']);
		if ($y->pos < count($y->lines)) {
			throw new InvalidArgumentException('Line '.$y->lines[$y->pos]['n'].': indented less than the lines above it, but not lined up with anything.');
		}
		return $out;
	}

	/**
	 * @param string $line Raw line
	 * @return string Without a # comment outside quotes
	 */
	private static function stripComment($line)
	{
		$quote = '';
		$len = strlen($line);
		for ($i = 0; $i < $len; $i++) {
			$c = $line[$i];
			if ($quote !== '') {
				if ($c === $quote) {
					$quote = '';
				}
			} elseif ($c === '"' || $c === "'") {
				$quote = $c;
			} elseif ($c === '#' && ($i == 0 || $line[$i - 1] === ' ')) {
				return rtrim(substr($line, 0, $i));
			}
		}
		return rtrim($line);
	}

	/**
	 * A map or a list whose lines all have this indent.
	 *
	 * @param int $indent Indent
	 * @return array
	 */
	private function block($indent)
	{
		$first = $this->lines[$this->pos];
		$isList = $first['text'] === '-' || strpos($first['text'], '- ') === 0;
		$out = array();
		while ($this->pos < count($this->lines)) {
			$l = $this->lines[$this->pos];
			if ($l['indent'] < $indent) {
				break;
			}
			if ($l['indent'] > $indent) {
				throw new InvalidArgumentException('Line '.$l['n'].': indented more than expected.');
			}
			$dash = $l['text'] === '-' || strpos($l['text'], '- ') === 0;
			if ($dash != $isList) {
				throw new InvalidArgumentException('Line '.$l['n'].': mixes "- " list items with "key: value" lines at the same indent.');
			}
			if ($isList) {
				$out[] = $this->listItem($l);
			} else {
				list($key, $value) = $this->pair($l);
				$this->pos++;
				$out[$key] = $value === '' ? $this->nested($indent) : self::scalar($value);
			}
		}
		return $out;
	}

	/**
	 * The block indented under the line just read, or '' when there is none.
	 *
	 * @param int $indent The parent's indent
	 * @return array|string
	 */
	private function nested($indent)
	{
		if ($this->pos < count($this->lines) && $this->lines[$this->pos]['indent'] > $indent) {
			return $this->block($this->lines[$this->pos]['indent']);
		}
		return '';
	}

	/**
	 * One "- ..." item: a scalar, or a map that starts on the dash line.
	 *
	 * @param array $l Line
	 * @return mixed
	 */
	private function listItem($l)
	{
		$rest = trim(substr($l['text'], 1));
		$this->pos++;
		if ($rest === '') {
			return $this->nested($l['indent']);
		}
		if (!self::isPair($rest)) {
			return self::scalar($rest);
		}
		// "- key: value" starts a map; its other keys line up with "key".
		$inner = $l['indent'] + (strlen($l['text']) - strlen(ltrim(substr($l['text'], 1)))) ;
		list($key, $value) = $this->pair(array('n' => $l['n'], 'text' => $rest));
		$map = array($key => $value === '' ? $this->nested($inner) : self::scalar($value));
		if ($this->pos < count($this->lines) && $this->lines[$this->pos]['indent'] == $inner) {
			$more = $this->block($inner);
			if (array_keys($more) === range(0, count($more) - 1)) {
				throw new InvalidArgumentException('Line '.$l['n'].': a list item mixes "key: value" with "- " lines.');
			}
			$map += $more;
		}
		return $map;
	}

	/**
	 * @param string $text Text
	 * @return bool It reads as "key: value" or "key:"
	 */
	private static function isPair($text)
	{
		return (bool) preg_match('/^("[^"]*"|\'[^\']*\'|[^"\'\[{][^:]*?):(\s|$)/', $text);
	}

	/**
	 * @param array $l Line
	 * @return array{0:string,1:string} Key and the text after the colon
	 */
	private function pair($l)
	{
		if (!preg_match('/^("[^"]*"|\'[^\']*\'|[^"\'\[{][^:]*?):(?:\s+(.*))?$/', $l['text'], $m)) {
			throw new InvalidArgumentException('Line '.$l['n'].': expected "name: value".');
		}
		return array((string) self::scalar(trim($m[1])), isset($m[2]) ? trim($m[2]) : '');
	}

	/**
	 * @param string $v Value text
	 * @return mixed String, or an array for [a, b]
	 */
	private static function scalar($v)
	{
		$v = trim($v);
		if ($v !== '' && $v[0] === '[' && substr($v, -1) === ']') {
			$inner = trim(substr($v, 1, -1));
			if ($inner === '') {
				return array();
			}
			return array_map(array('OnboardingYaml', 'scalar'), str_getcsv($inner, ',', '"', '\\'));
		}
		if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
			return substr($v, 1, -1);
		}
		return $v;
	}
}
