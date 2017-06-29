<?php
require_once (INCLUDE_DIR . 'class.signal.php');
require_once ('config.php');

/**
 * The goal of this Plugin is to read messages, find things that look like: @Grizly and add Agent Grizly to the collaborators, or things like @User and add that user
 *
 * Checks @email prefix for admin-defined domain.com it will find that user/agent via address lookup, then add them as a a collaborator.
 */
class MentionerPlugin extends Plugin {
	const DEBUG = FALSE;
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
			if (self::DEBUG) {
				error_log ( "ThreadEntry detected, checking for mentions and notifying staff." );
			}
			$this->checkThreadTextForMentions ( $entry );
			$this->notifyStaff ( $entry );
		} );
	}
	
	/**
	 * By default, Staff added as User-Collaborators only receive notifications when the original ticket creator sends a reply
	 * This isn't great..
	 * what if a collaborator sends a message? or a private note is sent that they should see?
	 *
	 * Code copied from Ticket::notifyCollaborators() and heavily modified
	 *
	 * @param ThreadEntry $entry        	
	 */
	private function notifyStaff(ThreadEntry $entry) {
		global $cfg;
		
		// aquire ticket from $entry
		$ticket_id = Thread::objects ()->filter ( [ 
				'id' => $entry->getThreadId () 
		] )->values_flat ( 'object_id' )->first () [0];
		
		// Need to pass the array, so we bypass the ticket-cache.. we want a fresh object with all the new collaborators..
		$ticket = Ticket::lookup ( [ 
				'ticket_id' => $ticket_id 
		] );
		// Get metadata
		$recipients = $ticket->getRecipients ();
		$dept = $ticket->getDept ();
		$tpl = ($dept) ? $dept->getTemplate () : $cfg->getDefaultTemplate ();
		$msg = $tpl->getNoteAlertMsgTemplate ();
		$email = ($dept) ? $dept->getEmail () : $cfg->getDefaultEmail ();
		$skip = array ();
		
		// Figure out if we need to send them.
		if ($entry->isSystem ()) {
			// System!
			$poster = $entry->getPoster (); // No idea what that returns.
			$skip [$ticket->getOwnerId ()] = true; // They don't need system messages.
				                                       // Actually, does anyone need system messages?
				                                       // return;
		} elseif ($entry->getUserId ()) {
			// A user sent us a message
			$poster = $entry->getUser ();
			$skip [$entry->getUserId ()] = true;
			
			// If the poster is the ticket owner, then bail, the normal message notification will fire (if enabled)
			if ($ticket->getOwnerId () == $entry->getUserId ()) {
				if (self::DEBUG)
					error_log ( "Skipping notification because the owner posted." );
				return;
			}
		} else {
			// An agent posted, skip that agent from being notified
			$poster = $entry->getStaff ();
			$skip [$entry->getStaffId ()] = true;
		}
		
		if ($entry instanceof NoteThreadEntry) {
			// Figure out who needs to receive this,
			// we want User's who are actually Agent's to get them, nobody else
			foreach ( $recipients as $r ) {
				if ($staff_object = Staff::lookup ( $r->getEmail () )) {
					// We want them to get it. They just happen to have a User account with the same email address.
					if (self::DEBUG) {
						error_log ( "We'll be emailing {$r->getName()}" );
					}
					continue;
				}
				// Otherwise, rightly, remove them.
				$skip [$r->getUserId ()] = true;
				if (self::DEBUG) {
					error_log ( "Removing {$r->getName()} from notification list." );
				}
			}
		}
		
		// Build a message to notify each collaborator
		$vars = [ 
				'message' => ( string ) $entry,
				'poster' => $poster ?: _S ( 'A collaborator' ) 
		];
		
		$msg = $ticket->replaceVars ( $msg->asArray (), $vars );
		
		$attachments = $cfg->emailAttachments () ? $entry->getAttachments () : array ();
		$options = [ 
				'thread' => $entry 
		];
		
		foreach ( $recipients as $recipient ) {
			// Skip the skippable
			if (isset ( $skip [$recipient->getUserId ()] ))
				continue;
			$notice = $ticket->replaceVars ( $msg, array (
					'recipient' => $recipient 
			) );
			$email->send ( $recipient, $notice ['subj'], $notice ['body'], $attachments, $options );
		}
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