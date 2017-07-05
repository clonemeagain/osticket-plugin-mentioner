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
				'sbm' => new SectionBreakField ( [ 
						'label' => $__ ( 'Who can be @mentioned and added as a Collaborator?' ),
						'hint' => $__ ( 'By default, all Agents and Users are available to be @mentioned' ) 
				] ),
				'at-mentions' => new BooleanField ( [ 
						'label' => $__ ( "Notice @mentions" ),
						'hint' => $__ ( 'Enables adding @collaborators' ),
						'default' => true 
				] ),
				'agents-only' => new BooleanField ( [ 
						'label' => $__ ( 'Only allow @mentions OF Agents (staff)' ),
						'hint' => $__ ( 'Add Agent collaborators only' ) 
				] ),
				'sba' => new SectionBreakField ( [ 
						'label' => $__ ( "Who can make mentions?" ),
						'hint' => $__ ( 'Default is all Users/Staff/System via email/web/API/scp' ) 
				] ),
				'by-agents-only' => new BooleanField ( [ 
						'label' => $__ ( 'Only allow #/@ mentions BY Agents' ),
						'hint' => $__ ( 'Uncheck to allow Users to mention' ),
						'default' => TRUE 
				] ),
				'on' => new SectionBreakField ( [ 
						'label' => $__ ( 'Override Notifications' ),
						'hint' => 'Ensure collaborators receive notifications about all Messages, if collaborator is Staff, they will receive notifications about Notes.' 
				] ),
				'override-notifications' => new BooleanField ( [ 
						'label' => $__ ( "Override Notifications" ),
						'hint' => $__ ( 'Can be dangerous..' ) 
				] ),
				'sbe' => new SectionBreakField ( [ 
						'label' => $__ ( 'Match email addresses?' ),
						'hint' => $__ ( 'If you put domain.com here, we\'ll try and match @user as user@domain.com and lookup their account (also works for #mention).' ) 
				] ),
				'email-domain' => new TextboxField ( [ 
						'label' => $__ ( 'Which domains to match emails for?' ),
						'configuration' => array (
								'size' => 40,
								'length' => 256 
						) 
				] ),
				'sbh' => new SectionBreakField ( [ 
						'label' => $__ ( 'Use #mentions for ticket notifications' ),
						'hint' => $__ ( 'Doesn\'t add as a collaborator, just notifies: "You were mentioned!".' ),
						'default' => TRUE 
				] ),
				'notice-hash' => new BooleanField ( [ 
						'label' => $__ ( 'Notice #Mentions' ),
						'hint' => $__ ( 'Sends notices to staff mentioned with #name' ) 
				] ),
				'notice-subject' => new TextboxField ( [ 
						'label' => $__ ( 'Notification Template: Subject' ),
						'hint' => $__ ( 'Subject of the notfication message' ),
						'default' => $__ ( 'You were mentioned in ticket #%{ticket.number}' ),
						'configuration' => array (
								'size' => 40,
								'length' => 256 
						) 
				] ),
				'notice-template' => new TextareaField ( [ 
						'label' => $__ ( 'Notification Template: Message' ),
						'hint' => $__ ( 'Use variables the same as Internal Note alert' ),
						'default' => '
<h3><strong>Hi %{recipient.name.first},</strong></h3>
						
<p>%{poster.name.short} mentioned you in ticket <a href="%%7Bticket.staff_link%7D">#%{ticket.number}</a></p>
<table><tbody>
<tr> <td> <strong>From</strong>: </td> <td> %{ticket.name} </td> </tr>
<tr> <td> <strong>Subject</strong>: </td> <td> %{ticket.subject} </td> </tr> </tbody></table>
<br /> %{comments} <br /><br /><hr />
<p>To view/respond to the ticket, please <a href="%%7Bticket.staff_link%7D"><span style="color:rgb(84, 141, 212)">login</span></a> to the support ticket system<br />
<em style="font-size:small">Your friendly Customer Support System</em>
<br /><img src="cid:b56944cb4722cc5cda9d1e23a3ea7fbc" alt="Powered by osTicket" width="126" height="19" style="width:126px" />
' 
				] )  // not sure if this src id will work for others.. might be better as a plaintext message template.
		
		);
	}
}
