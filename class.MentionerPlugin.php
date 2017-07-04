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
	
	/**
	 * To prevent buffer overflows, let's set the max length of a name we'll ever use to this:
	 *
	 * @var integer
	 */
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
		
		// Get metadata
		$ticket = $this->getTicket ( $entry );
		$recipients = $ticket->getRecipients ();
		$dept = $ticket->getDept ();
		$tpl = ($dept) ? $dept->getTemplate () : $cfg->getDefaultTemplate ();
		$msg = $tpl->getNoteAlertMsgTemplate ();
		$email = ($dept) ? $dept->getEmail () : $cfg->getDefaultEmail ();
		$skip = array ();
		
		// Add anyone who got #mention tagged but isn't already a collaborator?
		
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
				'poster' => $poster ?: _S ( 'A collaborator' ),
				'comments' => $entry->getBody ()->getClean () 
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
			
			if (self::DEBUG)
				error_log ( "Emailed {$recipient->getName()} about their collaboration" );
			$email->send ( $recipient, $notice ['subj'], $notice ['body'], $attachments, $options );
		}
	}
	
	/**
	 * Hunt through the text of a ThreadEntry's body text for mentions of Staff or Users
	 *
	 * @param ThreadEntry $entry        	
	 */
	private function checkThreadTextForMentions(ThreadEntry $entry) {
		// Get the contents of the ThreadEntryBody to check the text
		$text = $entry->getBody ()->getClean ();
		$match_staff_only = $this->getConfig ()->get ( 'agents-only' );
		
		// Match every instance of @name in the thread text
		if ($mentions = $this->getMentions ( $text, '@' )) {
			foreach ( $mentions as $idx => $name ) {
				// Look for @prefix as prefix@domain.com etc
				if ($m = $this->matchEmailDomain ( $name )) {
					if ($m instanceof Staff) {
						$this->addStaffCollaborator ( $entry, $m );
					} elseif (! $match_staff_only && $m instanceof User) {
						$this->addCollaborator ( $entry, $m );
					}
					continue;
				}
				$staff = $this->matchFirstLast ( $name );
				
				// Check for Staff with that name
				if (! $staff)
					$staff = Staff::lookup ( $name );
				
				if ($staff instanceof Staff) {
					$this->addStaffCollaborator ( $entry, $staff );
				} elseif (! $match_staff_only) {
					// It's not a Staff/Agent account, maybe it's a User:
					$user = User::lookup ( $name );
					if ($user instanceof User) {
						$this->addCollaborator ( $entry, $user );
					}
				}
			}
		}
		
		// Match every instance of #name in the thread text
		if ($this->getConfig ()->get ( 'notice-hash' ) && $mentions = $this->getMentions ( $text, '#' )) {
			$stafflist = new UserList ();
			foreach ( $mentions as $idx => $name ) {
				// Look for @prefix as prefix@domain.com etc
				if ($m = $this->matchEmailDomain ( $name )) {
					if ($m instanceof Staff) {
						$this->addStaffCollaborator ( $entry, $m );
					} elseif (! $match_staff_only && $m instanceof User) {
						$this->addCollaborator ( $entry, $m );
					}
					continue;
				}
				$staff = $this->matchFirstLast ( $name );
				
				// Check for Staff with that name
				if (! $staff)
					$staff = Staff::lookup ( $name );
				
				if ($staff instanceof Staff) {
					// Send a message to them telling them about the mention
					$stafflist->add ( $staff );
				}
			}
			if (count ( $stafflist )) {
				$this->notifyStaffOfMention ( $entry, $stafflist );
			}
		}
	}
	/**
	 * Send a body of text, and a prefix character to match with.
	 * It returns a list of matching words that are prefixed with that char.
	 *
	 * Ensures names are not longer than MAX_LENGTH_NAME
	 *
	 * @param string $text        	
	 * @param string $type        	
	 * @return array either of names or false if no matches.
	 */
	private function getMentions($text, $type = '@') {
		$matches = $mentions = array ();
		if (preg_match_all ( "/(^|\s)$type([\.\w]+)/i", $text, $matches ) !== FALSE) {
			if (count ( $matches [2] )) {
				$mentions = array_map ( function ($name) {
					// restrict length of $name's, prevent overflow
					return substr ( $name, 0, self::MAX_LENGTH_NAME );
				}, array_unique ( $matches [2] ) );
			}
		}
		if (self::DEBUG) {
			error_log ( "Matched $type " . count ( $mentions ) . ' matches.' );
			error_log ( print_r ( $mentions, true ) );
		}
		return $mentions ?: null;
	}
	
	/**
	 * Finds a Staff/User via their email prefix
	 *
	 * @param string $name        	
	 * @return boolean|Staff|User
	 */
	private function matchEmailDomain($name) {
		static $email_domain;
		if (! isset ( $email_domain )) {
			$email_domain = $this->getConfig ()->get ( 'email-domain' );
		}
		// TODO: Someone might want many domains here..
		if ($email_domain) {
			$email = "$name@$email_domain";
			
			// Trigger Staff match via the Email Validator (third)
			if ($staff = Staff::lookup ( $email )) {
				return $staff;
			}
			
			// Email might be for a User not Agent
			if ($user = User::lookupByEmail ( $email )) {
				return $user;
			}
		}
		return FALSE;
	}
	
	/**
	 * Creates a Staff object from a string like: "firstname.lastname"
	 *
	 * @param string $name        	
	 * @return NULL|Staff
	 */
	private function matchFirstLast($name) {
		if (strpos ( $name, '.' ) !== FALSE) {
			list ( $fn, $ln ) = explode ( '.', $name );
			return Staff::lookup ( array (
					'firstname' => $fn,
					'lastname' => $ln 
			) );
		}
		return null;
	}
	
	/**
	 * Craft/Fetch a User to match the Staff account and adds as User
	 *
	 * @param ThreadEntry $entry        	
	 * @param Staff $staff        	
	 */
	private function addStaffCollaborator(ThreadEntry $entry, Staff $staff) {
		//
		$vars = array (
				'name' => $name,
				'email' => $staff->getEmail () 
		);
		$user = User::fromVars ( $vars, true );
		if (self::DEBUG)
			error_log ( "Converting {$staff->getName()} to User" );
		$this->addCollaborator ( $entry, $user );
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
		if (self::DEBUG)
			error_log ( "Adding collaborator: {$user->getName()}" );
		
		$entry->getThread ()->addCollaborator ( $user, $vars, $errors, true );
	}
	
	/**
	 * Simply crafts an email to send to Staff members that they have been mentioned.
	 *
	 * Uses custom message template defined in plugin config. :-)
	 *
	 * @param ThreadEntry $entry        	
	 * @param UserList $staff
	 *        	(of Staff objects)
	 */
	private function notifyStaffOfMention(ThreadEntry $entry, UserList $recipients) {
		// aquire ticket from $entry
		global $cfg;
		$ticket = $this->getTicket ( $entry );
		
		$msg = new EmailTemplate ( 0 );
		$msg->id = 'plugin-fake'; // Likely needs to be an integer..
		                          
		// Hack up some data that we might have properly used the admin stuff for, but didn't
		$msg->ht = [ 
				'tpl_id' => PHP_INT_MAX -1, // dunno.. works. Figure it's unlikely any normal install would have that many templates.
				'code_name' => 'cannedresponse', // trigger "ticket" root context for the VariableReplacer
				'subject' => $this->getConfig ()->get ( 'notice-subject' ),
				'body' => $this->getConfig ()->get ( 'notice-template' ) ,
				'updated' => time(),
		];
		$msg->_group = 3; //HTML Group
		
		$dept = $ticket->getDept ();
		$email = ($dept) ? $dept->getEmail () : $cfg->getDefaultEmail ();
		$poster = $entry->getPoster ();
		
		// Build data for the template processor: (no need to inject $ticket, it does that)
		$vars = [ 
				'message' => ( string ) $entry,
				'poster' => $poster,
				'comments' => $entry->getBody ()->getClean () 
		];
		// Use the ticket to convert the template to a message:
		$msg = $ticket->replaceVars ( $msg->asArray (), $vars );
		$attachments = $cfg->emailAttachments () ? $entry->getAttachments () : array ();
		$options = [ 
				'thread' => $entry,
				'notice' => TRUE  // Automated notice, don't bounce back to me!
		];
		// Send the notice
		foreach ( $recipients as $recipient ) {
			$notice = $ticket->replaceVars ( $msg, array (
					'recipient' => $recipient 
			) );
			if (self::DEBUG)
				error_log ( "Emailed {$recipient->getName()} about their #mention with subj {$notice['subj']}" );
			$email->send ( $recipient, $notice ['subj'], $notice ['body'], $attachments, $options );
		}
	}
	
	/**
	 * Fetches a ticket from a ThreadEntry
	 *
	 * @param ThreadEntry $entry        	
	 * @return Ticket
	 */
	private static function getTicket(ThreadEntry $entry) {
		static $ticket;
		if (! $ticket) {
			// aquire ticket from $entry
			$ticket_id = Thread::objects ()->filter ( [ 
					'id' => $entry->getThreadId () 
			] )->values_flat ( 'object_id' )->first () [0];
			
			// Force lookup rather than cached data..
			$ticket = Ticket::lookup ( array (
					'ticket_id' => $ticket_id 
			) );
		}
		return $ticket;
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