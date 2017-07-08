<?php

class Email_Reader
{
    // imap server stream
    public $stream;

    // inbox storage and inbox message count
    public $inbox;
    public $msg_count;

    // options
    public $options = [
        'raw' => false
    ];

    // mailbox credentials
    private $mailbox;
    private $username;
    private $password;

    // connect to the server and get the inbox emails
    function __construct($mailbox, $username, $password, $options = [])
    {
        $this->mailbox  = $mailbox;
        $this->username = $username;
        $this->password = $password;

        if ( !empty($options) && is_array($options) ) {
            $this->options = array_merge($this->options, $options);
        }

        if ( !$this->connect() ) {
            throw new Exception(sprintf("Could not connect to mailbox '%s' with username '%s'", $this->mailbox, $this->username));
        }
    }

    // close the server stream
    function close()
    {
        if ( $this->stream !== null && $this->stream !== false ) {
            return imap_close($this->stream);
        }
    }

    // open the server stream
    function connect()
    {
        if ( $this->mailbox !== null && $this->username !== null && $this->password !== null ) {
            if ( $this->stream = imap_open($this->mailbox, $this->username, $this->password) ) {
                return true;
            } else {
                throw new Exception(sprintf("Invalid IMAP stream (mailbox: %s, username: %s)", $this->mailbox, $this->username));
            }
        } else {
            throw new Exception(sprintf("Missing IMAP settings: mailbox, username, and/or password"));
        }
    }

    // move the message to a folder
    function move($uid, $folder, $expunge = true)
    {
        if ( $this->stream !== null && $this->stream !== false ) {

            $tries = 0;

            while ( $tries++ < 3 ) {
                if ( imap_mail_move($this->stream, $uid, $folder, CP_UID) ) {
                    if ( $expunge ) {
                        imap_expunge($this->stream);
                    }
                    return true;
                } else {
                    sleep(1);
                }
            }

        }

        return false;
    }

    // get a specific message
    function get_message($uid, $raw = null)
    {
        if ( $this->stream !== null && $this->stream !== false ) {

            if ( $raw === null ) {
                $raw = $this->options['raw'];
            }

            $raw_header  = imap_fetchheader($this->stream, $uid, FT_UID);
            $raw_body    = imap_body($this->stream, $uid, FT_UID);
            $raw_message = $raw_header . $raw_body;

            if ( $raw ) {
                return $raw_message;
            } else {
                if ( class_exists('Email_Parser') ) {
                    return new Email_Parser($raw_message);
                }
            }

        }
    }

    function get_messages($max = 50, $return_uid = false)
    {
        return $this->search('ALL', $max, $return_uid);
    }

    function search($criteria, $max = 50, $return_uid = false)
    {
        if ( $this->stream !== null && $this->stream !== false ) {

            $messages = [];

            $results = imap_search($this->stream, $criteria, SE_UID);

            $i = 0;

            if ( !empty($results) ) {
                foreach ( $results as $uid ) {
                    if ( $i++ < $max || $max < 0 ) {
                        if ( $return_uid ) {
                            $messages[] = $uid;
                        } else {
                            $messages[$uid] = $this->get_message($uid);
                        }
                    } else {
                        break;
                    }
                }
            }

            return $messages;

        }

        return false;
    }

    function get_unread()
    {
        return $this->search('UNSEEN', -1);
    }
}
