<?php
require_once INCLUDE_DIR . 'class.plugin.php';
class MentionerPluginConfig extends PluginConfig {
	// Provide compatibility function for versions of osTicket prior to
	// translation support (v1.9.4)
	function translate() {
		if (! method_exists ( 'Plugin', 'translate' )) {
			return array (
					function ($x) {
						return $x;
					},
					function ($x, $y, $n) {
						return $n != 1 ? $y : $x;
					} 
			);
		}
		return Plugin::translate ( 'mentioner' );
	}
	
	/**
	 * Build an Admin settings page.
	 *
	 * {@inheritdoc}
	 *
	 * @see PluginConfig::getOptions()
	 */
	function getOptions() {
		list ( $__, $_N ) = self::translate ();
		return array (
				'sb1' => new SectionBreakField ( [ 
						'label' => $__ ( 'Who can be @mentioned?' ),
						'hint' => $__ ( 'By default, all Agents and Users are avaialble' ) 
				] ),
				'agents-only' => new BooleanField ( [ 
						'label' => $__ ( 'Only allow mentions from Agents (staff)' ) 
				] ),
				'sb' => new SectionBreakField ( [ 
						'label' => $__ ( 'Match email addresses?' ),
						'hint' => $__ ( 'If you put domain.com here, we\'ll try and match @user as user@domain.com and lookup their account.' ) 
				] ),
				'email-domain' => new TextboxField ( [ 
						'label' => $__ ( 'Which domains to match emails for?' ),
						'configuration' => array (
								'size' => 40,
								'length' => 256 
						) 
				] ) 
		);
	}
}
