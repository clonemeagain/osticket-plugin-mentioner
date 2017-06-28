<?php
require_once (INCLUDE_DIR . 'class.signal.php');

/**
 * The goal of this Plugin is to read messages, find things that look like: @Grizly and add Agent Grizly to the collaborators, or things like @User and add that user
 *
 * if you use @email@address.com it will find that user/agent via address lookup, strip the first @ and add them as a a collaborator.
 */
class MentionerPlugin extends Plugin {
	const DEBUG = TRUE;
	
	/**
	 * Run on every instantiation of osTicket..
	 * needs to be concise
	 *
	 * {@inheritdoc}
	 *
	 * @see Plugin::bootstrap()
	 */
	function bootstrap() {
		Signal::connect ( 'threadentry.created', function ($entry) {
			$ticket_id = Thread::objects ()->filter ( array (
					'id' => $entry->getThreadId () 
			) )->values_flat ( 'object_id' )->first () [0]; // YMMV, tested on PHP7
			
			$t = Ticket::lookup ( $ticket_id );
			
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
							
							// Check for Staff first? Can you even add staff? Shit.
							$s = Staff::lookup ( $name );
							
							if ($s instanceof Staff) {
								// We'll craft a User to match
								$vars = array (
										'name' => $name,
										'email' => $s->getEmail () 
								);
								$o = User::fromVars ( $vars, true );
							} else {
								$o = User::lookup ( $name );
							}
							
							if ($o) {
								// Attempt to add the collaborator
								$vars = $errors = array ();
								// Skip ticket check
								$t->addCollaborator ( $o, $vars, $errors, true );
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