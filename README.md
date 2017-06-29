# osTicket plugin: Mentioner

Parses messages for Mentions, and adds users/agents emails or names as collaborators on a ticket.

Concept pinched as lazily as possible from: http://osticket.com/forum/discussion/comment/115623



## To Install
Download release zip/gz and extract into /include/plugins/mentioner and Install as per normal osTicket Plugins

## To configure

Visit the Admin-panel, select Manage => Plugins, choose the Mentioner plugin

You can determine if only Agents can be mentioned (might be useful)
You can enter a domain-name to allow email matching. 

If you set "domain.com", any @mention becomes mention@domain.com and both an Agent and User lookup by that email is performed. (unless you restrict to Agents only, then only a Staff lookup is done). 
If no email match is found, it does a normal name lookup.