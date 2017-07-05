# osTicket plugin: Mentioner

Parses incoming messages for @mentions, adds users/agents emails or names as collaborators on a ticket.
Can also notice #mentions and send a notification to that person that they've been mentioned.

eg:
```
You'll want to have @clonemeagain have a look at it.
```
Will add user clonemeagain as a collaborator on the ticket. If that is a Staff member, a User account will be created with the same Name & Email as the Staff account then added.

```
Guys, pick amongst yourselves who needs to deal with this.
#tim #beerguy #additionalgenericcop
```
Will send three "You've been mentioned in a ticket" emails, one to each.

Bonus feature:
You can use @name and #name's inside Canned Responses!


Concept pinched from: http://osticket.com/forum/discussion/comment/115623



## To Install
Download master [zip](https://github.com/clonemeagain/osticket-plugin-mentioner/archive/master.zip) or [release](https://github.com/clonemeagain/osticket-plugin-mentioner/releases) and extract into /include/plugins/mentioner and Install and enable as per normal osTicket Plugins

## To configure

Visit the Admin-panel, select Manage => Plugins, choose the Mentioner plugin

- Option to Enable or disable the adding of collaborators via @mention (Notice @mentions)
- Option to Filter @mention collaborator adding to only Staff members (Only allow @mentions OF Agents(staff))
- Option to Filter for @mention and #mention to only BY Staff (Only allow #/@ mentions BY Agents)
- Option to Override Notifications: Ensures collaborators get notified about activity in the thread. The default is to only tell them about Messages sent by the thread originator, if you want Staff collaborators to know about internal notes or Messages sent by collaborators etc, then tick this. Note: This can be dangerous, if a Staff User is added incorrectly or in error as a collaborator, they will receive all internal notes! (including attachments if enabled). (Override Notifications). 
- Option to enter a domain-name which allows email matching. This means, if you have domain "github.com" entered, using #someting will match email address someting@github.com and will perform a Staff lookup for that address, if none found, will perform a User lookup for that address.
- Option to Enable checking for #mentions (Notice #Mentions) 
- Option to configure the Notification template for #mentions Uses same template variables as a Staff ticket.

## What constitutes an @mention?
A few extra checks are done beyond a simple Staff username test!
 
1. We check for Staff/User email address (if enabled) first (most accurate)
1. Then we check for Staff firstname.lastname (quite accurate)
1. Then we check for Staff Username (unlikely that all staff/users even know the usernames, doesn't work with spaces)
1. Then we check for User's name (Quite difficult to match.. likely useless, doesn't work with spaces)

## What constitutes an #mention?
Same criteria as @mention, but only Staff/Agents are matched. 