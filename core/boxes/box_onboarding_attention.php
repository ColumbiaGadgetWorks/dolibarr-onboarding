<?php
/* Columbia Gadget Works member onboarding module for Dolibarr.
 * License: GPL-3.0-or-later
 *
 * Home page box: what needs attention, with counts (see attention.php).
 */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

/**
 * Needs attention box.
 */
class box_onboarding_attention extends ModeleBoxes
{
	public $boxcode = 'onboardingattention';
	public $boximg = 'object_member';
	public $boxlabel = 'Needs attention';
	public $depends = array('onboarding');

	/** @var DoliDB */
	public $db;
	/** @var string */
	public $param;
	/** @var int */
	public $enabled = 1;
	/** @var array */
	public $info_box_head = array();
	/** @var array */
	public $info_box_contents = array();

	/**
	 * @param DoliDB $db Database handler
	 * @param string $param More parameters
	 */
	public function __construct($db, $param = '')
	{
		$this->db = $db;
		$this->param = $param;
		$this->hidden = !isModEnabled('onboarding');
	}

	/**
	 * @param int $max Most lines
	 * @return void
	 */
	public function loadBox($max = 10)
	{
		global $user;
		dol_include_once('/onboarding/class/attention.class.php');
		$this->info_box_head = array('text' => 'Needs attention', 'sublink' => '', 'limit' => 0);
		$attention = new OnboardingAttention($this->db);
		$line = 0;
		foreach ($attention->items($user) as $i) {
			if ($i['count'] <= 0) {
				continue;
			}
			$this->info_box_contents[$line][0] = array('td' => '', 'text' => $i['label'], 'url' => $i['url'], 'asis' => 0);
			$this->info_box_contents[$line][1] = array('td' => 'class="right"', 'text' => '<span class="badge '.($i['urgent'] ? 'badge-status8' : 'badge-status1').'">'.$i['count'].'</span>', 'asis' => 1);
			$line++;
		}
		if (!$line) {
			$this->info_box_contents[0][0] = array('td' => 'class="center opacitymedium"', 'text' => 'Nothing needs attention.');
		}
		$this->info_box_contents[$line ?: 1][0] = array('td' => 'class="right" colspan="2"', 'text' => 'All checks', 'url' => dol_buildpath('/onboarding/attention.php', 1));
	}

	/**
	 * @param array|null $head Head
	 * @param array|null $contents Lines
	 * @param int $nooutput Return instead of print
	 * @return string
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
	}
}
