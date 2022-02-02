# tiny-imap-filter

A simple mail client written in PHP to apply filtering rules to a set of mailboxes.

I just changed my mail provider, and found out that there was no way for me to define local mail processing rules. No whitelist, nothing. So I decided to write
a small PHP program that operates on a set of simple rules to whitelist mails, move them around or to delete them entirely.

There is already such a project [imapfilter](https://github.com/lefcha/imapfilter). Unfortunately, I do not have root access to my web space, so I cannot install it. But at least, I have access to cron jobs so I can run a PHP program at certain intervals.

### Description

tiny-imap-filter is a small PHP program that reads a file in PHP INI format and processes the rules found in that file. You can define a good set of really simple
rules for various email providers, mailboxes and folders. The file `imapfilter.php` can be used as a command-line utility or as a Web page. The latter lets you debug the rules before actually applying them.

In case of errors, the program sends an email with a list of the errors.

### Browser Usage

As a web page, simply call the script from a browser. Without any arguments, the script acts just as if called from the command line. With an argument `?debug=`,
emails are not modified, and debugging output is echoed back to the browser.

There are three debugging options available:

- `?debug=true` prints the from, to, and subject fields of the email and any actions.
- `?debug=match` also prints the matches.
- `?debug=config` simply dumps the parsed INI file.

### INI file format

See also the file `imapfilter.sample.ini` for more info.

#### Rules
The rules are executed in the following order:
1) All mailbox specific rules without any action (mailbox whitelisting)
2) All global rules without any action (global whitelisting)
3) All mailbox specific rules with action verbs
4) All global rules with action verbs

As soon as a rule matches, processing stops.

The INI key is the rule name, and the value is a string containing the rule; this is a three-word condition followed by action verbs. Generally, if a word contains spaces, you can use single quotes to quote these words as in 'my name'. So, the rule format is `fieldname condition testvalue [actions]`.

Condition and action verbs are case insensitive, while field names, values and mailbox folders are case sensitive. If the action is missing, the rule just matches and leaves the message as-is. This is called whitelisting, and makes sure that a message is left untouched by any other rules.

The field name is the field name of the email header, case-sensitive. The email header fields such as "from", "to", "cc" and "bcc" have two subfields "mailbox", "host" and "personal", where "personal" is the full name is present. Address these subfields with a dot, like "from.mailbox" to access the sender name without the  "@domain.tld" field. If you use email header fields without a subfield, the email address is used.

Fields that are actually arrays becasue there may e.g. be multiple recipients may cause problems if a rule happens to match an email address that is part of that array. Therefore, you can use the config setting "domains" to define a list of domains that rules apply to. See below for more info.

Example: the value "\<My Name\> name@example.com" in the "from" field is available as:
```
from.mailbox = "name"
from.host = "example.com"
from.personal = "My Name"
from = "name@example.com"
```
Please refer to the PHP function [imap_headerinfo()](http://php.net/manual/en/function.imap-headerinfo.php) for a full list of field names and values.

The 2nd word is the condition verb, followed by a value. The following verbs are available:

- *is* - exact match: `to is test@example.com`
- *contains* - substring match: `to contains example`
- *starts-with* - true if the value starts with the value: `to starts-with tes`
- *ends-with* - true if the value ends with the value: `to ends-with .com`
- *matches* - regular expression match: to matches `.+@example.com`
- *not-xxxx* - uses the verbs above like *not-is* to invert the condition

Numeric conditions include =, != <= >< >= and >

The following action verbs are defined:
- *mark-read* - mark message as read
- *mark-unread* - mark message as unread
- *delete* - delete the message
- *move-to folder* - move message (folder name is case sensitive): move-to Trash
- *next-rule name* - if true, try the rule with the given name, allows for AND chaining  of rules. You can only use rules defined in the same segment, though.
```
; Examples:
move-spam = "subject starts-with [SPAM] mark-read move-to Junk"
; If the recipient name contains two or more digits, delete as junk:
delete    = "to matches \d\d delete"
; If the recipient is myself, and the subject contains "Golf", move to "Golf" folder
golf1     = "to.mailbox is golf next-rule golf2"
golf2     = "subject contains Golf move-to Golf"
```
#### [config]

This section contains the settings for the email and other global settings.

- *from*: the sender
- *to*: the To (recipient) field for error emails
- *cc* and *bcc*: optional files for more email addresses
- *subject*: The subject, also used as the headline
- *domains*: This is a space-separated list of domains that should be checked. Email addresses that do not match these domains are ignored. If you leave the list empty or moit it completely, all email addresses will be subject to the rules.

#### [connection]

Connection sections have freely definable names. They contain the data required to access a specific mailbox folder. You can specify as many connections as you would like to. The word "connection" above is just a placeholder.
- *host*: The host is the server name, optionally followed by a port number and other options. The most common options are /imap and /ssl or /tls. Please refer to the PHP function [imap_open()](http://php.net/manual/en/function.imap-open.php) for a full list of options. If no IMAP options are supplied, /imap is assumed.
- *user*: the username
- *pass*: the password
- *folder*: This optional value takes the folder to operate upon. If not supplies, "INBOX" is assumed.
- *search*: This is the IMAP search expression that should be used on that connection. This is based on the possible IMAP search verbs described at the PHP function [imap_search()](http://php.net/manual/en/function.imap-search.php). The string is case insensitive, and you can use any date speficier that the PHP function [DateTime::modify()](http://php.net/manual/en/datetime.modify.php) supports as a word; it will be replaced with the correct date format. If you, for example, use the string "SINCE yesterday", all emails headers that have arrived since yesterday would be fetched. If this value is omitted, the search verb is "UNSEEN", which returns unread messages. Please keep in mind to use single quote3s for multi-word expression as in "SINCE '-3 days'".

#### [connection.rules]

In this section, you can specify connection specific rules. The INI section name is the connection name, followed by ".rules". Since it may be easier to just speficy global rules, this section may be omitted.
