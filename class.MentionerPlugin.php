<?php
require_once (INCLUDE_DIR . 'class.signal.php');

/**
 * The goal of this Plugin is to read messages, find things that look like: @Grizly and add Agent Grizly to the collaborators, or things like @User and add that user
 *
 * TODO: use @email@address.com it will find that user/agent via address lookup, strip the first @ and add them as a a collaborator.
 */
class MentionerPlugin extends Plugin {
	const DEBUG = FALSE;
	
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
						foreach ( $mentions as $idx => $name ) {
							$name = ltrim ( $name, '@' );
							
							// Check for Staff with that name: Can you even add staff? Nope.. only Users.
							$s = Staff::lookup ( $name );
							
							if ($s instanceof Staff) {
								// Craft a User to match the Staff account:
								$vars = array (
										'name' => $name,
										'email' => $s->getEmail () 
								);
								$o = User::fromVars ( $vars, true );
							} else {
								// It's not a Staff/Agent account, maybe it's a user, or a Staff-User we've previously created:
								$o = User::lookup ( $name );
							}
							
							if ($o) {
								// Attempt to add the collaborator to the thread (won't duplicate by default)
								$vars = $errors = array ();
								$entry->getThread ()->addCollaborator ( $o, $vars, $errors, true );
							}
						}
					}
				}
			}
		} );
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