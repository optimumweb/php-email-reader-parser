<?php

require_once('Mail/mimeDecode.php');

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

    public function parse($raw)
    {
        $this->raw = $raw;

        // http://pear.php.net/manual/en/package.mail.mail-mimedecode.decode.php
        $decoder = new Mail_mimeDecode($this->raw);

        $this->decoded = $decoder->decode([
            'decode_headers' => true,
            'include_bodies' => true,
            'decode_bodies'  => true
        ]);

        $this->from    = mb_convert_encoding($this->decoded->headers['from'],    $this->charset, $this->charset);
        $this->to      = mb_convert_encoding($this->decoded->headers['to'],      $this->charset, $this->charset);
        $this->subject = mb_convert_encoding($this->decoded->headers['subject'], $this->charset, $this->charset);
        $this->date    = mb_convert_encoding($this->decoded->headers['date'],    $this->charset, $this->charset);

        $this->from_email = preg_replace('/.*<(.*)>.*/', "$1", $this->from);

        if ( isset($this->decoded->parts) && is_array($this->decoded->parts) ) {
            foreach ( $this->decoded->parts as $idx => $body_part ) {
                $this->decode_part($body_part);
            }
        }

        if ( isset($this->decoded->disposition) && $this->decoded->disposition == 'inline' ) {
            $mime_type = "{$this->decoded->ctype_primary}/{$this->decoded->ctype_secondary}";

            if ( isset($this->decoded->d_parameters) && array_key_exists('filename', $this->decoded->d_parameters) ) {
                $filename = $this->decoded->d_parameters['filename'];
            } else {
                $filename = 'file';
            }

            if ( $this->is_valid_attachment($mime_type) ) {
                $this->save_attachment($filename, $this->decoded->body, $mime_type);
            }

            $this->body = "";
        }

        // We might also have uuencoded files. Check for those.
        if ( empty($this->body) ) {
            if ( isset($this->decoded->body) ) {
                $this->body = $this->decoded->body;
            } else {
                $this->body = "";
            }
        }

        if ( preg_match("/begin ([0-7]{3}) (.+)\r?\n(.+)\r?\nend/Us", $this->body) > 0 ) {
            foreach ( $decoder->uudecode($this->body) as $file ) {
                // $file = [ 'filename' => $filename, 'fileperm' => $fileperm, 'filedata' => $filedata ]
                $this->save_attachment($file['filename'], $file['filedata']);
            }
            // Strip out all the uuencoded attachments from the body
            while ( preg_match("/begin ([0-7]{3}) (.+)\r?\n(.+)\r?\nend/Us", $this->body) > 0 ) {
                $this->body = preg_replace("/begin ([0-7]{3}) (.+)\r?\n(.+)\r?\nend/Us", "\n", $this->body);
            }
        }

        $this->body = mb_convert_encoding($this->body, $this->charset, $this->charset);

        return $this;
    }

    private function decode_part($body_part)
    {
        if ( isset($body_part->ctype_parameters) && is_array($body_part->ctype_parameters) ) {
            if ( array_key_exists('name', $body_part->ctype_parameters) ) {
                $filename = $body_part->ctype_parameters['name'];
            } elseif ( array_key_exists('filename', $body_part->ctype_parameters) ) {
                $filename = $body_part->ctype_parameters['filename'];
            }
        } elseif ( isset($body_part->d_parameters) && is_array($body_part->d_parameters) ) {
            if ( array_key_exists('filename', $body_part->d_parameters) ) {
                $filename = $body_part->d_parameters['filename'];
            }
        }
        if ( !isset($filename) ) {
            $filename = "file";
        }

        $mime_type = "{$body_part->ctype_primary}/{$body_part->ctype_secondary}";

        if ( $this->debug ) {
            print "Found body part type $mime_type\n";
        }

        if ( $body_part->ctype_primary == 'multipart' ) {
            if ( is_array($body_part->parts) ) {
                foreach ( $body_part->parts as $ix => $sub_part ) {
                    $this->decode_part($sub_part);
                }
            }
        } elseif ( !isset($body_part->disposition) || $body_part->disposition == 'inline' ) {
            switch ( $mime_type ) {
                case 'text/plain':
                    $this->body .= $body_part->body . "\n";
                    break;
                case 'text/html':
                    $this->html .= $body_part->body . "\n";
                    break;
                default:
                    if ( $this->is_valid_attachment($mime_type) ) {
                        $this->save_attachment($filename, $body_part->body, $mime_type);
                    }
            }
        } else {
            if ( $this->is_valid_attachment($mime_type) ) {
                $this->save_attachment($filename, $body_part->body, $mime_type);
            }
        }
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
        $filename = mb_convert_encoding($filename, $this->charset, $this->charset);

        $dot_ext = '.'.self::get_file_extension($filename);

        $unlocked_and_unique = false;
        $i = 0;

        while ( !$unlocked_and_unique && $i++ < 10 ) {

            $name = uniqid('email_attachment_');
            $path = sys_get_temp_dir() . '/' . $name . $dot_ext;

            // Attempt to lock
            $outfile = fopen($path, 'w');

            if ( flock($outfile, LOCK_EX) ) {
                $unlocked_and_unique = true;
            } else {
                flock($outfile, LOCK_UN);
                fclose($outfile);
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
            return $this->body;
        } elseif ( !empty($this->html) ) {
            $plain  = strip_tags($this->html, '<style>');
            $substr = substr($plain, strpos($plain, "<style"), strpos($plain, "</style>") + 2);
            $plain  = str_replace($substr, "", $plain);
            $plain  = str_replace([ "\t", "\r", "\n" ], "", $plain);
            return trim($plain);
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