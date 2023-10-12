<?php

namespace TgEmail;

use TgUtils\Date;

/**
 * An Email object to be sent or persisted.
 * @author ralph
 *        
 */
class Email {

    const PENDING    = 'pending';
    const PROCESSING = 'processing';
    const SENT       = 'sent';
    const FAILED     = 'failed';
    const HTML       = 'html';
    const TEXT       = 'text';

    /* Example priority */
    const PRIO_HIGH   = 10;
    const PRIO_NORMAL = 0;
    const PRIO_LOW    = -10;

    public $uid;
    public $status;
    public $failed_attempts;
    public $sent_time;
    public $queued_time;
    public $update_time; // FWAM
    
    /**
     * mail priority (-int,0,int)
     * +higher, -lower
     * @var int
     */
    public $priority = self::PRIO_NORMAL; // FWAM
    
    public $sender;
    public $recipients;
    public $reply_to;
    public $subject;
    public $body;
    public $attachments;
    
    /**
     * Default Constructor.
     */
    public function __construct() {
    }

    /**
     * @return int Email::PRIO_
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * @param int $p Email::PRIO_*
     * @return $this
     */
    public function setPriority(int $p = self::PRIO_NORMAL)
    {
        $this->priority = $p;
        return $this;
    }

    public function getSender() {
        if ($this->sender != NULL) {
            if (!is_object($this->sender)) {
                $this->sender = EmailAddress::from($this->sender);
            }
        }
        return $this->sender;
    }
    
    public function setSender($email, $name = NULL) {
        $this->sender = EmailAddress::from($email, $name);
        return $this;
    }
    
    public function getReplyTo() {
        if ($this->reply_to != NULL) {
            if (!is_object($this->reply_to)) {
                $this->reply_to = EmailAddress::from($this->reply_to);
            }
        }
        return $this->reply_to;
    }
    
    public function setReplyTo($email, $name = NULL) {
        $this->reply_to = EmailAddress::from($email, $name);
        return $this;
    }
    
    protected function getRecipients() {
        if ($this->recipients == NULL) {
            $this->recipients = new \stdClass;
            $this->recipients->to  = array();
            $this->recipients->cc  = array();
            $this->recipients->bcc = array();
        }
        if (!is_object($this->recipients)) {
            $this->recipients = json_decode($this->recipients);
            $this->recipients->to  = $this->convertToAddresses($this->recipients->to);
            $this->recipients->cc  = $this->convertToAddresses($this->recipients->cc);
            $this->recipients->bcc = $this->convertToAddresses($this->recipients->bcc);
        }
        return $this->recipients;
    }
    
    protected function convertToAddresses($arr) {
        $rc = array();
        foreach ($arr AS $address) {
            $rc[] = EmailAddress::from($address);
        }
        return $rc;
    }
    
    public function getTo() {
        return $this->getRecipients()->to;
    }
    
    public function addTo($address, $name = NULL) {
        if (is_array($address)) {
            foreach ($address AS $a) {
                $this->addTo($a);
            }
        } else if (is_string($address)) {
            $this->getRecipients()->to[] = EmailAddress::from($address, $name);
        } else if (is_object($address)) {
            $this->getRecipients()->to[] = EmailAddress::from($address);
        } else {
            throw new EmailException('Cannot add TO recipient(s)');
        }
        return $this;        
    }
            
    public function getCc() {
        return $this->getRecipients()->cc;
    }
    
    public function addCc($address, $name = NULL) {
        if (is_array($address)) {
            foreach ($address AS $a) {
                $this->addCc($a);
            }
        } else if (is_string($address)) {
            $this->getRecipients()->cc[] = EmailAddress::from($address, $name);
        } else if (is_object($address)) {
            $this->getRecipients()->cc[] = EmailAddress::from($address);
        } else {
            throw new EmailException('Cannot add CC recipient(s)');
        }
        return $this;        
    }
            
    public function getBcc() {
        return $this->getRecipients()->bcc;
    }
    
    public function addBcc($address, $name = NULL) {
        if (is_array($address)) {
            foreach ($address AS $a) {
                $this->addBcc($a);
            }
        } else if (is_string($address)) {
            $this->getRecipients()->bcc[] = EmailAddress::from($address, $name);
        } else if (is_object($address)) {
            $this->getRecipients()->bcc[] = EmailAddress::from($address);
        } else {
            throw new EmailException('Cannot add BCC recipient(s)');
        }
        return $this;        
    }
         
    public function getSubject() {
        return $this->subject;
    }
    
    public function setSubject($s) {
        $this->subject = $s;
        return $this;
    }
    
    public function getBody($type = 'text') {
        if (($this->body != NULL) && is_string($this->body)) {
            $this->body = json_decode($this->body);
        } else if ($this->body == NULL) {
            $this->body = new \stdClass;
        }
        if (isset($this->body->$type)) {
            return $this->body->$type;
        }
        return NULL;
    }
    
    public function setBody($type = 'text', $body = '') {
        if (($this->body != NULL) && is_string($this->body)) {
            $this->body = json_decode($this->body);
        } else if ($this->body == NULL) {
            $this->body = new \stdClass;
        }
        $this->body->$type = $body;
        return $this;
    }
    
    public function getAttachments() {
        if ($this->attachments == NULL) {
            $this->attachments = array();
        } else if (is_string($this->attachments)) {
            $arr = json_decode($this->attachments);
            $this->attachments = array();
            foreach ($arr AS $a) {
                $this->attachments[] = Attachment::from($a);
            }
        }
        return $this->attachments;
    }
    
    public function addAttachment(Attachment $a) {
        $this->getAttachments();
        $this->attachments[] = $a;
        return $this;
    }

    public function addAttachments(array $arr) {
        $this->getAttachments();
        foreach ($arr AS $a) {
            $this->attachments[] = $a;
        }
        return $this;
    }
    
    public function getSentTime($timezone = 'UTC') {
        if (($this->sent_time != NULL) && is_string($this->sent_time)) {
            $this->sent_time = new Date($this->sent_time, $timezone);
        }
        return $this->sent_time;
    }
    
    public function getQueuedTime($timezone = 'UTC') {
        if (($this->queued_time != NULL) && is_string($this->queued_time)) {
            $this->queued_time = new Date($this->queued_time, $timezone);
        }
        return $this->queued_time;
    }
    
    public function getUpdateTime($timezone = 'UTC') {
        if (($this->update_time != NULL) && is_string($this->update_time)) {
            $this->update_time = new Date($this->update_time, $timezone);
        }
        return $this->update_time;
    }
    
    public function getLogString() {
        $rc  =   'TO='.$this->stringify($this->getRecipients()->to);
        $rc .=  ' CC='.$this->stringify($this->getRecipients()->cc);
        $rc .= ' BCC='.$this->stringify($this->getRecipients()->bcc);
        return $rc;
    }
    
    public function stringify($addresses) {
        $rc = array();
        foreach ($addresses AS $a) {
            $rc[] = $a->__toString();
        }
        return implode(',', $rc);
    }
}

