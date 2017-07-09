<?php

class Email_Parser
{
    private $allowed_mime_types    = [];
    private $disallowed_mime_types = [];

    private $charset = 'UTF-8';

    private $debug = false;

    public $raw         = null;
    public $decoded     = null;
    public $from        = null;
    public $from_email  = null;
    public $to          = null;
    public $cc          = null;
    public $reply_to    = null;
    public $subject     = null;
    public $date        = null;
    public $body        = "";
    public $html        = "";
    public $attachments = [];

    public function __construct($raw = null)
    {
        if ( !empty($raw) ) {
            $this->parse($raw);
        }
    }

    public function parse($raw = null)
    {
        if ( $raw !== null ) {
            $this->raw = $raw;
        }

        if ( $this->raw !== null ) {

            $parser = new PhpMimeMailParser\Parser;

            $parser->setText($this->raw);

            $this->from     = $parser->getHeader('from');
            $this->to       = $parser->getHeader('to');
            $this->cc       = $parser->getHeader('cc');
            $this->reply_to = $parser->getHeader('reply-to');
            $this->subject  = $parser->getHeader('subject');
            $this->date     = $parser->getHeader('date');

            if ( $this->from ) {
                $this->from_email = preg_replace('/.*<(.*)>.*/', "$1", $this->from);
            }

            $this->body = $parser->getMessageBody('text');
            $this->html = $parser->getMessageBody('html');

            $attachments = $parser->saveAttachments(sys_get_temp_dir());

            $this->attachments = $attachments;

            /*if ( !empty($attachments) ) {
                foreach ( $attachments as $attachment ) {
                    if ( $mime_type = $attachment->getContentType() ) {
                        if ( $this->is_valid_attachment($mime_type) ) {
                            if ( $contents = base64_decode($attachment->getMimePartStr()) ) {
                                $this->save_attachment($attachment->getFilename(), $contents, $mime_type);
                            }
                        }
                    }
                }
            }*/

        }

        return $this;
    }

    private function is_valid_attachment($mime_type)
    {
        if ( empty($this->allowed_mime_types) || in_array($mime_type, $this->allowed_mime_types) ) {
            if ( empty($this->disallowed_mime_types) || !in_array($mime_type, $this->disallowed_mime_types) ) {
                return true;
            }
        }
        return false;
    }

    private function save_attachment($filename, $contents, $mime_type = 'unknown')
    {
        $dot_ext = '.' . self::get_file_extension($filename);

        $unlocked_and_unique = false;
        $i = 0;

        while ( !$unlocked_and_unique && $i++ < 10 ) {

            $name = uniqid('email_attachment_');
            $path = sys_get_temp_dir() . '/' . $name . $dot_ext;

            if ( $outfile = fopen($path, 'w') ) {
                if ( flock($outfile, LOCK_EX) ) {
                    $unlocked_and_unique = true;
                } else {
                    flock($outfile, LOCK_UN);
                    fclose($outfile);
                }
            }

        }

        if ( isset($outfile) && $outfile !== false ) {
            fwrite($outfile, $contents);
            fclose($outfile);
        }

        if ( isset($name, $path) ) {
            $this->attachments[] = [
                'name' => $filename,
                'path' => $path,
                'size' => $this->format_bytes(filesize($path)),
                'mime' => $mime_type
            ];
        }
    }

    public function plain()
    {
        if ( !empty($this->body) ) {
            $text = $this->body;
        } elseif ( !empty($this->html) ) {
            $text = $this->html;
        }

        if ( !empty($text) ) {
            $plain  = strip_tags($text, '<style>'); // remove all tags but style tags
            $substr = substr($plain, strpos($plain, "<style"), strpos($plain, "</style>") + 2); // take care of style tags manually to remove inline css
            $plain  = str_replace($substr, "", $plain); // remove all css
            $plain  = str_replace([ "\t" ], "", $plain); // remove tabs
            $plain  = preg_replace("/\n\n+/", "\n\n", $plain); // remove excessive line-breaks that come from stripping tags
            return trim($plain); // trim extra white-space
        }
    }

    private function format_bytes($bytes, $precision = 2)
    {
        $units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    private static function get_file_extension($filename)
    {
        if ( substr($filename, 0, 1) == '.' ) {
            return substr($filename, 1);
        }
        $pieces = explode('.', $filename);
        if ( count($pieces) > 1 ) {
            return strtolower(array_pop($pieces));
        }
    }
}
