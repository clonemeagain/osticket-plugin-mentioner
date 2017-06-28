<?php
require_once (INCLUDE_DIR . 'class.signal.php');
require_once ('config.php');

/**
 * The goal of this Plugin is to read messages, find things that look like: @Grizly and add Agent Grizly to the collaborators, or things like @User and add that user
 *
 * Checks @email prefix for admin-defined domain.com it will find that user/agent via address lookup, then add them as a a collaborator.
 */
class MentionerPlugin extends Plugin {
	/**
	 * Which config to use (in config.php)
	 *
	 * @var string
	 */
	public $config_class = 'MentionerPluginConfig';
	const MAX_LENGTH_NAME = 128;
	
	/**
	 * Run on every instantiation of osTicket..
	 * needs to be concise
	 *
	 * {@inheritdoc}
	 *
	 * @see Plugin::bootstrap()
	 */
	function bootstrap() {
		Signal::connect ( 'threadentry.created', function (ThreadEntry $entry) {
			$this->checkThreadTextForMentions ( $entry );
		} );
	}
	
	/**
	 * Function was getting a bit long to be lambda..
	 *
	 * @param ThreadEntry $entry        	
	 */
	private function checkThreadTextForMentions(ThreadEntry $entry) {
		// Get the contents of the ThreadEntryBody to check the text
		$text = $entry->getBody ()->getClean ();
		
		// Initial check for the @ symbol..
		if (strpos ( $text, '@' ) !== FALSE) {
			// We've an @.. let's check it for Mentions!
			$matches = array ();
			
			// regex pinched from: https://stackoverflow.com/a/10384251/276663
			if (preg_match_all ( '/(^|\s)(@\w+)/i', $text, $matches ) !== FALSE) {
				if (count ( $matches [2] )) {
					$mentions = array_unique ( $matches [2] );
					
					// Fetch admin config:
					$c = $this->getConfig (); // (PluginConfig)
					$match_staff_only = $c->get ( 'agents-only' ); // (bool)
					$email_domain = $c->get ( 'email-domain' ); // (string)
					
					foreach ( $mentions as $idx => $name ) {
						$name = ltrim ( $name, '@' ); // remove @ prefix
						$name = substr ( $name, 0, self::MAX_LENGTH_NAME ); // restrict the length of $name, prevent overflow
						
						if (! $name)
							continue;
						
						// TODO: Someone might want many domains here..
						if ($email_domain) {
							$email = "$name@$email_domain";
							
							// Trigger Staff match via the Email Validator (third)
							$staff = Staff::lookup ( $email );
							
							// Email might be for a User not Agent
							if (! $staff && ! $match_staff_only) {
								$user = User::lookupByEmail ( $email );
								if ($user instanceof User) {
									$this->addCollaborator ( $entry, $user );
									continue;
								}
							}
						}
						
						// Check for Staff with that name (skip if email matched)
						if (! $staff)
							$staff = Staff::lookup ( $name );
						
						if ($staff instanceof Staff) {
							// Craft a User to match the Staff account:
							$vars = array (
									'name' => $name,
									'email' => $staff->getEmail () 
							);
							$user = User::fromVars ( $vars, true );
						} elseif (! $match_staff_only) {
							// It's not a Staff/Agent account, maybe it's a user, or a Staff-User we've previously created:
							$user = User::lookup ( $name );
						}
						
						if ($user instanceof User) {
							$this->addCollaborator ( $entry, $user );
						}
					}
				}
			}
		}
	}
	
	/**
	 * Adds a collaborating User to the ThreadEntry
	 *
	 * @param ThreadEntry $entry        	
	 * @param User $user        	
	 */
	private function addCollaborator(ThreadEntry $entry, User $user) {
		// Attempt to add the collaborator to the thread (won't duplicate by default)
		$vars = $errors = array ();
		$entry->getThread ()->addCollaborator ( $user, $vars, $errors, true );
	}
	
	/**
	 * Required stub.
	 *
	 * {@inheritdoc}
	 *
	 * @see Plugin::uninstall()
	 */
	function uninstall() {
		$errors = array ();
		parent::uninstall ( $errors );
	}
	
	/**
	 * Plugin seems to want this.
	 */
	public function getForm() {
		return array ();
	}
}