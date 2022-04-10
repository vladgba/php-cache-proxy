
# PHP Cache Proxy
Proxy for forced caching of site files with the ability to open without an Internet connection (OperaMini_4.21_mod from J2ME but server-side)

Works on PHP 7.4.

## Installation

1. Download the source code as located within this repository, and upload it to your web server.
2. Use `db.sql` to create the `cachedpages` table in a database of choice.
3. Edit `index.php` and enter your database credentials.

# How to use
 - Just open in browser page: 
http://[yourhost.com]/[targetsite]::[targetpath]
 - For example:
http://192.168.1.1:8080/google.com::search?q=news
