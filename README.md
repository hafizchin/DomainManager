# Omeka S - Domain Manager #
## A plugin for Omeka S ##

This plugin allows Omeka S users to map a site to a domain.

| Omeka S                                         | Domain Manager                     | 
| :---------------------------------------------- | :--------------------------------- | 
| www.yourdomain.com/s/site-slug/page/page-name   | www.yourdomain.com/page/page-name  | 

Sample virtual host configuration (Apache):
```
<VirtualHost *:443>
     ServerName omeka-main.mydomain.com
     ServerAlias omeka-site-1.mydomain.com
     ServerAlias icanbewhatever.com
</VirtualHost>
```
`ServerName` is the url of your **primary site** and any additional sites will be a `ServerAlias` entry.
