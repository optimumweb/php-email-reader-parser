<?php

class Email_Reader
{
    // imap server stream
    public $stream;

    // inbox storage and inbox message count
    public $inbox;
    public $msg_count;

    // mailbox credentials
    private $mailbox;
    private $username;
    private $password;

    // connect to the server and get the inbox emails
    function __construct($mailbox, $username, $password)
    {
        $this->mailbox  = $mailbox;
        $this->username = $username;
        $this->password = $password;

        $this->connect();
    }

    // close the server stream
    function close()
    {
        imap_close($this->stream);
    }

    // open the server stream
    function connect()
    {
        $this->stream = imap_open($this->mailbox, $this->username, $this->password);

        if ( $this->stream === false ) {
            trigger_error(sprintf("Invalid IMAP stream (Mailbox: %s / Username: %s)", $this->mailbox, $this->username), E_USER_WARNING);
        }
    }

    // move the message to a new folder
    function move($uid, $folder = 'INBOX.Processed')
    {
        if ( imap_mail_move($this->stream, $uid, $folder, CP_UID) ) {
            imap_expunge($this->stream);
            return true;
        }

        return false;
    }

    // get a specific message
    function get_message($uid)
    {
        $raw_header = imap_fetchheader($this->stream, $uid, FT_UID);
        $raw_body   = imap_body($this->stream, $uid, FT_UID);

        $message = new Email_Parser($raw_header.$raw_body);

        return $message;
    }

    function get_messages($max = 50, $return_uid = false)
    {
        return $this->search('ALL', $max, $return_uid);
    }

    function search($criteria, $max = 50, $return_uid = false)
    {
        $messages = [];

        $results = imap_search($this->stream, $criteria, SE_UID);

        $i = 0;

        if ( $results ) {
            foreach ( $results as $result_uid ) {
                if ( $i++ < $max || $max < 0 ) {
                    if ( $return_uid ) {
                        $messages[] = $result_uid;
                    } else {
                        $messages[$result_uid] = $this->get_message($result_uid);
                    }
                } else {
                    break;
                }
            }
        }

        return $messages;
    }

    function get_unread()
    {
        return $this->search('UNSEEN', -1);
    }
}
