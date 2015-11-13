# php-email-reader-parser

## What does it do?

It does something that should be so simple but turns out to be very complex using PHP: reading emails from a mailbox using IMAP and then parsing the returned emails into a dev-friendly object.

## Requirements

- PHP 5.4+
- PHP PEAR
- PHP IMAP extension
- PHP mbstring extension (mb_convert_encoding)

## Installation

Simply require both classes (Email_Reader and Email_Parser) inside your code.

```php
<?php

require_once('PATH_TO_FILES/email_reader.php');
require_once('PATH_TO_FILES/email_parser.php');

?>
```

* Note that you can use the Email_Parser class by itself without the Email_Reader class, but not the other way around.

## How to use it?

Email_Reader is used to open an IMAP stream to a mailbox and then fetch messages.

```php
<?php

$reader = new Email_Reader($mailbox, $username, $password);

$messages = $reader->get_messages();

$unread = $reader->get_unread();

?>
```

Email_Parser is used within Email_Reader to be able to decode the returned emails. It can also be used by itself to read emails, for example, from php://std in the case of email piping.

```php
#! /usr/bin/php -q
<?php

$fd = fopen("php://stdin", "r");
$raw = "";
while ( !feof($fd) ) {
    $raw .= fread($fd, 1024);
}
fclose($fd);

$email = new Email_Parser($raw);

doWhaterever($email->from, $email->subject, $email->body);

?>
```

## Footnotes

In order to write these classes, I had a lot of reading of other people's work and I would like to thank the following repository owners.

- [eXorus](https://github.com/eXorus) for [php-mime-mail-parser](https://github.com/php-mime-mail-parser/php-mime-mail-parser)

## Author

Jonathan Roy, web developper at [OptimumWeb](http://optimumweb.ca)

Twitter: [@jonathanroy](https://twitter.com/jonathanroy)

