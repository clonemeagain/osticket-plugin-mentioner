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
	 * Define some class constants for the source of an entry
	 *
	 * @var integer
	 */
	const Staff = 0;
	const User = 1;
	const System = 2;
	
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
			$this->notifyCollaborators ( $entry );
		} );
	}
	
	/**
	 * Hunt through the text of a ThreadEntry's body text for mentions of Staff or Users
	 *
	 * @param ThreadEntry $entry        	
	 */
	private function checkThreadTextForMentions(ThreadEntry $entry) {
		// Get the contents of the ThreadEntryBody to check the text
		$text = $entry->getBody ()->getClean ();
		$config = $this->getConfig ();
		
		// Check if Poster has been allowed to make mentions:
		if ($config->get ( 'by-agents-only' ) && $this->getPoster ( $entry ) != self::Staff) {
			if (self::DEBUG) {
				error_log ( "Ignoring action by non-staff due to configuration." );
			}
			return;
		}
		// Check if source method allowed
		// On my test install (with 400k thread entries), almost 3k have a source sent.. it must be a manual thing
		// $source = $entry->getSource ();
		
		// Match every instance of @name in the thread text
		if ($this->getConfig ()->get ( 'at-mentions' ) && $mentions = $this->getMentions ( $text, '@' )) {
			// Each unique name will get added as a Collaborator to the ticket thread.
			foreach ( $mentions as $idx => $name ) {
				$this->addCollaborator ( $entry, $name );
			}
		}
		
		// Match every instance of #name in the text
		if ($this->getConfig ()->get ( 'notice-hash' ) && $mentions = $this->getMentions ( $text, '#' )) {
			// Build a recipient list, each unique name will get checked for Staff-ishness
			$stafflist = new UserList ();
			foreach ( $mentions as $idx => $name ) {
				$staff = $this->convertName ( $name, TRUE );
				if ($staff instanceof Staff) {
					if (self::DEBUG) {
						error_log ( "Adding {$staff->getName()} to #notifications list." );
					}
					$stafflist->add ( $staff );
				}
			}
			if (count ( $stafflist )) {
				// Send the recipient list a message about the notice.
				$this->notifyStaffOfMention ( $entry, $stafflist );
			}
		}
	}
	
	/**
	 * Looks for a Agent/User with name: $name
	 *
	 * @param string $name        	
	 * @param boolean $staff_only
	 *        	(don't look in User table)
	 * @return Staff|null|User
	 */
	private function convertName($name, $staff_only = false) {
	    // Names aren't numbers
	    if(is_numeric($name))
	        return null;
	    
		// Look for @prefix as prefix@domain.com etc
		if ($m = $this->matchEmailDomain ( $name )) {
			if ($m instanceof Staff) {
				return $m;
			} elseif (! $staff_only && $m) {
				return $m;
			}
		}
		// Look for first.last
		if ($staff = $this->matchFirstLast ( $name ))
			return $staff;
		
		// Check for Staff via username
		if ($staff = Staff::lookup ( $name ))
			return $staff;
		
		// It's not a Staff/Agent account, maybe it's a User:
		if (! $staff_only && $user = User::lookup ( $name ))
			return $user;
		
		return null;
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
	private function notifyCollaborators(ThreadEntry $entry) {
		global $cfg;
		
		if (! $this->getConfig ()->get ( 'override-notifications' )) {
			// Admin doesn't want this
			return;
		}
		
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
			// $poster = $entry->getPoster (); // No idea what that returns.
			// $skip [$ticket->getOwnerId ()] = true; // They don't need system messages.
			// Actually, does anyone need system messages?
			return;
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
		} else {
			// MessageThreadEntries..
			// Skip the original author, they'll already get notified
			$skip [$ticket->getOwnerId ()] = true;
		}
		
		// Build a message to notify each collaborator
		$vars = [ 
				'message' => ( string ) $entry,
				'poster' => $poster ?: _S ( 'A collaborator' ),
				'note' => array (
						'title' => $entry->getTitle (),
						'message' => $entry->getBody ()->getClean () 
				) 
		];
		
		// Use the ticket to convert the template to a message: (replaces variables with content)
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
	 * Looks through $text & finds a list of words that are prefixed with $prefix char.
	 *
	 * Ensures names are not longer than MAX_LENGTH_NAME
	 *
	 * @param string $text        	
	 * @param string $prefix        	
	 * @return array either of names or false if no matches.
	 */
	private function getMentions($text, $prefix = '@') {
		$matches = $mentions = array ();
		if (preg_match_all ( "/(^|\s)?$prefix([\.\w]+)/i", $text, $matches ) !== FALSE) {
			if (count ( $matches [2] )) {
				$mentions = array_map ( function ($name) {
					// restricts length of $name's, prevent overflow
					return substr ( $name, 0, self::MAX_LENGTH_NAME );
				}, array_unique ( $matches [2] ) );
			}
		}
		if (self::DEBUG) {
			error_log ( "Matched $prefix " . count ( $mentions ) . ' matches.' );
			error_log ( print_r ( $mentions, true ) );
		}
		return isset ( $mentions [0] ) ? $mentions : null; // fastest validator ever.
	}
	
	/**
	 * Finds a Staff/User via their email prefix
	 *
	 * Requires admin to have configured email-domain config option.
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
	 * Find a Staff object from a string like: "firstname.lastname"
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
	 * Adds someone to the ticket as a collaborator.
	 *
	 * @param ThreadEntry $entry        	
	 * @param string $name        	
	 */
	private function addCollaborator(ThreadEntry $entry, $name) {
		$actor = $this->convertName ( $name, $this->getConfig ()->get ( 'agents-only' ) );
		if ($actor instanceof Staff) {
			$this->addStaffCollaborator ( $entry, $actor );
		} elseif (! $match_staff_only) {
			if ($actor instanceof User) {
				$this->addUserCollaborator ( $entry, $actor );
			}
		}
	}
	
	/**
	 * Craft/Fetch a User to match the Staff account and adds as User
	 *
	 * @param ThreadEntry $entry        	
	 * @param Staff $staff        	
	 */
	private function addStaffCollaborator(ThreadEntry $entry, Staff $staff) {
		$vars = array (
				'name' => $staff->getName (),
				'email' => $staff->getEmail () 
		);
		$user = User::fromVars ( $vars, true );
		if (self::DEBUG)
			error_log ( "Converting {$staff->getName()} to User" );
		$this->addUserCollaborator ( $entry, $user );
	}
	
	/**
	 * Adds a collaborating User to the ThreadEntry (won't duplicate by default)
	 *
	 * @param ThreadEntry $entry        	
	 * @param User $user        	
	 */
	private function addUserCollaborator(ThreadEntry $entry, User $user) {
		if (self::DEBUG)
			error_log ( "Adding collaborator: {$user->getName()}" );
		$vars = $errors = array ();
		$entry->getThread ()->addCollaborator ( $user, $vars, $errors, true );
	}
	
	/**
	 * Crafts an email to send to Staff members that they have been mentioned.
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
		$msg->id = 'plugin-fake';
		
		// Simulate correct EmailTemplate variables:
		$msg->ht = [ 
				'tpl_id' => PHP_INT_MAX - 1, // It's unlikely any normal install would have that many templates... famous last words
				'code_name' => 'cannedresponse', // HACK: trigger "ticket" root context for the VariableReplacer
				'subject' => $this->getConfig ()->get ( 'notice-subject' ),
				'body' => $this->getConfig ()->get ( 'notice-template' ),
				'updated' => time ()  // We're always updating this template.. :-)
		];
		$msg->_group = 3; // HTML Group
		
		$dept = $ticket->getDept ();
		$email = ($dept) ? $dept->getEmail () : $cfg->getDefaultEmail ();
		$poster = $entry->getPoster ();
		
		// Build data for the template processor: (no need to inject $ticket, it does that)
		$vars = [ 
				'message' => ( string ) $entry,
				'poster' => $poster,
				'comments' => $entry->getBody ()->getClean () 
		];
		// Use the ticket to convert the template to a message: (replaces variables with content)
		// Note: $msg->asArray returns subject as subj.. annoying
		$msg = $ticket->replaceVars ( $msg->asArray (), $vars );
		$attachments = $cfg->emailAttachments () ? $entry->getAttachments () : array ();
		$options = [ 
				'thread' => $entry,
				'notice' => TRUE  // Automated notice, don't bounce back to me!
		];
		// Send to each
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
			// aquire ticket from $entry.. I suspect there is a more efficient way.
			$ticket_id = Thread::objects ()->filter ( [ 
					'id' => $entry->getThreadId () 
			] )->values_flat ( 'object_id' )->first () [0];
			
			// Force lookup rather than use cached data..
			$ticket = Ticket::lookup ( array (
					'ticket_id' => $ticket_id 
			) );
		}
		return $ticket;
	}
	
	/**
	 * Enumerate the originator of the message.
	 *
	 * @param ThreadEntry $entry        	
	 * @return EntryPoster constant
	 */
	private function getPoster(ThreadEntry $entry) {
		if ($entry->getStaffId ()) {
			return self::Staff;
		} elseif ($entry->getUserId ()) {
			return self::User;
		}
		return self::System;
	}
	
	/**
	 * Required stub.
	 *
	 * {@inheritdoc}
	 *
	 * @see Plugin::uninstall()
	 */
	function uninstall(&$errors) {
		$errors = array ();
		parent::uninstall ( $errors );
	}
	
	/**
	 * Plugins seem to want this.
	 */
	public function getForm() {
		return array ();
	}
}


