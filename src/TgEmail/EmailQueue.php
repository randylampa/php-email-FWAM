<?php

namespace TgEmail;

use TgLog\Log;
use TgUtils\Date;
use TgUtils\Request;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Central mail handler using PHPMailer.
 *
 * @author ralph
 *        
 */
class EmailQueue {

    /** Constant for blocking any mail sending */
    const BLOCK = 'block';
    /** Constant for rerouting mails to admin users */
    const REROUTE = 'reroute';
    /** Constant for adding admin user to BCC */
    const BCC = 'bcc';
    /** Constant for default mail sending */
    const DEFAULT = 'default';
    
    protected $mailer;

    protected $config;

    /**
     * @var EmailsDAO
     */
    protected $mailDAO;

    /**
     * @var MailerWrapper[]
     */
    protected $mailerWrappers = [];
    
    public function __construct($config, EmailsDAO $mailDAO = NULL) {
        $this->config  = $config;
        $this->mailDAO = $mailDAO;
        $this->mailer  = NULL;
    }

    public function createTestMail() {
        $rc = new Email();
        $rc->setSender($this->config->getDefaultSender());
        $rc->setBody(Email::TEXT, 'This is a successfull e-mail test (TXT)');
        $rc->setBody(Email::HTML, '<html><body><h1>Success</h1><p>This is a successfull e-mail test (HTML)</p></body></html>');
        $rc->addTo($this->config->getDebugAddress());
        $rc->setSubject($this->config->getSubjectPrefix() . 'Test-Mail');
        return $rc;
    }
    
    /**
     * Sends a test-mail to private account
     */
    public function sendTestMail() {
        $email = $this->createTestMail();
        return $this->_send($email);
    }
    
    /**
     * Set a new mail mode.
     * @param string $mailMode - the new mail mode
     * @param object $config   - the configuration of this mail mode (optional when config already available or not required)
     */
    public function setMailMode($mailMode, $config = NULL) {
        $this->config->setMailMode($mailMode, $config);
    }
        
    protected function getMailer(): PHPMailer {
        if ($this->mailer == null) {
            $smtpConfig = $this->config->getSmtpConfig();
            $this->mailer = new PHPMailer();
            MailerWrapper::mailerDebugBasic($this->mailer, $smtpConfig->getDebugLevel() ?: SMTP::DEBUG_OFF); // init debug
            $this->mailer->isSMTP(); // telling the class to use SMTP
            $this->mailer->SMTPKeepAlive = true;
            $this->mailer->SMTPAuth   = $smtpConfig->isAuth();
            $this->mailer->SMTPSecure = $smtpConfig->getSecureOption();
            $this->mailer->Port       = $smtpConfig->getPort();
            $this->mailer->Host       = $smtpConfig->getHost();
            $this->mailer->Username   = $smtpConfig->getUsername();
            $this->mailer->Password   = $smtpConfig->getPassword();
            $this->mailer->CharSet    = $smtpConfig->getCharset();
            $this->mailer->Encoding   = PHPMailer::ENCODING_BASE64;
            //$this->mailer->Encoding   = PHPMailer::ENCODING_QUOTED_PRINTABLE;

            $debugConnect = '';
            $oldDbgCfg = MailerWrapper::mailerDebugOutput($this->mailer, $debugConnect);
            $bc = $this->mailer->smtpConnect(); // perform connect to log
            MailerWrapper::mailerDebugBasic($this->mailer, ...$oldDbgCfg);
            if (0 || !$bc) {
                // do something with log stored in $debugConnect on failed connect
                echo("<div><h5>Connect</h5><textarea>$debugConnect</textarea><br/>{$this->mailer->ErrorInfo}</div>");
            }
        } else {
            $this->mailer->clearAllRecipients();
            $this->mailer->clearAttachments();
            $this->mailer->clearCustomHeaders();
            $this->mailer->clearReplyTos();
        }
        return $this->mailer;
    }

	/**
	 * Returns the mail queue object (DAO).
     * @return EmailsDAO
     */
	public function getQueue() {
		return $this->mailDAO;
	}

    /**
     * First added wrapper will be used as default. Adding another object with same name will overwrite the previous!
     * @param MailerWrapper $wrapper
     * @return self
     */
    public function addMailerWrapper(MailerWrapper $wrapper): self
    {
        $this->mailerWrappers[$wrapper->getNameHash()] = $wrapper;
        return $this;
    }

    /**
     * @param string $nameHash $wrapper->getNameHash() or $email->send_by_cfg
     * @return MailerWrapper
     * @throws MailerWrapperNotFoundException
     */
    public function getMailerWrapper(string $nameHash): MailerWrapper
    {
        if (array_key_exists($nameHash, $this->mailerWrappers)) {
            return $this->mailerWrappers[$nameHash];
        }
        throw new MailerWrapperNotFoundException('Mailer wrapper not found!');
    }

    /**
     * @return MailerWrapper
     * @throws MailerWrapperNotFoundException
     */
    public function getDefaultMailerWrapper(): MailerWrapper
    {
        $wrapper = reset($this->mailerWrappers);
        if(!$wrapper){
            throw new MailerWrapperNotFoundException('Default mailer wrapper not set!');
        }
        return $wrapper;
    }

    /**
     * @return bool
     */
    public function testMailerWrapperConnections(): bool
    {
        $allConnected = true;
        foreach ($this->mailerWrappers as $wrapper) {
            $phpMailer = $wrapper->getPhpMailerInstance();
            $connected = $phpMailer->smtpConnect();
            if (!$connected) {
                dump(['failed to connect', $wrapper]);
            }
            $allConnected &= $connected;
        }
        return $allConnected;
    }

    /**
     * @param MailerWrapper $wrapper
     * @return MailerWrapper
     */
    protected function fwam_initMailerWrapper(MailerWrapper $wrapper): MailerWrapper
    {
        /* reuse old object */
        try {
            $wrapper = $this->getMailerWrapper($wrapper->getNameHash());
            dump(['got existing wrapper']);
        } catch (MailerWrapperNotFoundException $ex) {
            $this->addMailerWrapper($wrapper);
            dump(['storing wrapper']);
        }
        /* /reuse old object */

        if ($this->mailDAO) {
            $wrapper->refreshLimits($this->mailDAO);
        }
        dump([$wrapper, $wrapper->getName(), $wrapper->getNameHash()]); // musí být před pokusem o inicializaci nového
        if (!$wrapper->canSendAnother()) {
            try {
                $wrapperAlt = $wrapper->getAlternativeWrapper();
                // try alternative
                $wrapper = $this->fwam_initMailerWrapper($wrapperAlt);
            } catch (MailerWrapperNotFoundException $ex) {
                // has no other alternative.. left current
                dump($ex, $this);
            }
        }
        return $wrapper;
    }

    /**
     * @param Email $email
     * @return MailerWrapper
     */
    protected function fwam_getMailerWrapperForEmail(Email $email): MailerWrapper
    {
        // store wrapper instance in protected property of object $email.. providers are stored in pool, setAlternativeWrapper uses links

        if (isset($email->_fwamMailerWrapper) && $email->_fwamMailerWrapper) {
            return $email->_fwamMailerWrapper;
        }

        $wrapper = null;
        if (isset($email->send_via_cfg) && $email->send_via_cfg) {
            // get mailer according to $email->send_via_cfg (from pool) ...
            try {
                $wrapper = $this->getMailerWrapper($email->send_via_cfg);
                dump(['got existing wrapper by $email->send_via_cfg']);
            } catch (MailerWrapperNotFoundException $ex) {
                // silent nothing
            }
        }

        if (!$wrapper) {
            // ... else use default
            try {
                $wrapper = $this->getDefaultMailerWrapper();
                dump(['got default wrapper']);
            } catch (MailerWrapperNotFoundException $ex) {
                // get new default SMTP for now
                $wrapper = new MailerWrapper($this->config->getSmtpConfig());
            }
        }

        $email->_fwamMailerWrapper = $this->fwam_initMailerWrapper($wrapper);

        return $email->_fwamMailerWrapper;
    }

    /**
     * @param Email $email
     * @return PHPMailer
     */
    protected function fwam_getMailerForEmail(Email $email): PHPMailer
    {
        $wrapper = $this->fwam_getMailerWrapperForEmail($email);
        $phpMailer = $wrapper->getPhpMailerInstance();
        return $phpMailer;
    }

    /**
     * @param Email $email
     * @return bool
     */
    protected function fwam_canSendEmail(Email $email): bool
    {
        //dump([__METHOD__, func_get_args(), debug_backtrace()]);
//        return true;
        $wrapper = $this->fwam_getMailerWrapperForEmail($email);
        return $wrapper->canSendAnother();
    }

    /**
     * @param Email $email
     */
    protected function fwam_updateMailerProps(Email $email)
    {
        //dump([__METHOD__, func_get_args(), debug_backtrace()]);
        $wrapper = $this->fwam_getMailerWrapperForEmail($email);
        $wrapper->setSent();

        $email->status = Email::SENT;
        $email->sent_via_cfg = $wrapper->getNameHash();

        if (!$email->uid && $this->mailDAO) {
            // store to queue as
            $this->mailDAO->create($email);
        }
    }

    /**
     * Synchronously send emails from queue according to priority.
     */
    public function processQueue($maxTime = 0) {
        if ($maxTime <= 0) $maxTime = 60;

        // numbers are subject of change
        $maxLimitMinute = 250; // 250
        $maxLimitHour = 1000; // 1000
        $loadMaxPending = $maxTime < 60 ? $maxLimitMinute : $maxLimitHour;

        if ($this->mailDAO != NULL) {
            // Make sure the request object was created
            Request::getRequest();
            
            // Return statistics
            $rc = new \stdClass();
            $rc->pending   = 0;
            $rc->skipped   = 0;
            $rc->processed = 0;
            $rc->sent      = 0;
            $rc->failed    = 0;
        
            // do housekeeping
            $this->mailDAO->housekeeping();
            
            // Retrieve pending emails
            //$emails = $this->mailDAO->getPendingEmails(); // this is very very wasteful, you do not have to load entire object, if you need just uid...
            $emails = $this->mailDAO->getPendingEmailUids($loadMaxPending);
            //dump($emails);
            $rc->pending = count($emails);
            foreach ($emails as $email) {
//                $em2 = new Email();
//                $em2->uid = $email->uid;
//                if (!$this->fwam_canSendEmail($em2)) {
//                    $rc->skipped++;
//                    continue;
//                }
                // send
                if ($this->sendByUid($email->uid, TRUE)) {
                    $rc->sent++;
                } else {
                    $rc->failed++;
                }
                $rc->processed++;
                if (Request::getRequest()->getElapsedTime() > $maxTime) break;
            }
            return $rc;
        }
        throw new EmailException('QueueProcessing not supported. No DAO available.');
    }

    /**
     * Synchronously send email from queue with id.
     */
    public function sendByUid($uid, $checkStatus = FALSE) {
        if ($this->mailDAO != NULL) {
            // Retrieve
            $email = $this->mailDAO->get($uid);
            //dump($email);
            
            if ($email != NULL) {
                if (!$this->fwam_canSendEmail($email)) {
                    // repeat test 'cos its different entry
                    return false;
                }
                // Mark as being processed
                $email->status = Email::PROCESSING;
                $rc_s = $this->mailDAO->save($email);
                if (!$rc_s) {
                    // do something if save failed???
                }

                // send
                $rc = $this->_send($email);
                
                // Save
                $email->status = Email::SENT;
                if (!$rc) {
                    $email->failed_attempts ++;
                    if ($email->failed_attempts >= 3) {
                        $email->status = Email::FAILED;
                        foreach ($email->getAttachments() AS $a) {
                            if ($a->deleteAfterSent && $a->deleteAfterFailed) {
                                unlink($a->path);
                            }
                        }
                    } else {
                        $email->status = Email::PENDING;
                    }
                } else {
                    $email->sent_time = new Date(time(), $this->config->getTimezone());
                }
                $this->mailDAO->save($email);
                return $rc;
            }
            return FALSE;
        }
        throw new EmailException('No DAO available. Cannot retrieve e-mail by ID.');
    }

    /**
     * Creates a new Email object that reflects the MailMode settings.
     */
    public function getReconfiguredEmail(Email $email) {
        $rc = new Email();
        
        if ($email->getSender() != NULL) {
            $rc->setSender($email->getSender());
        } else {
            $rc->setSender($this->config->getDefaultSender());
        }
        $rc->setPriority($email->getPriority());
        $rc->setReplyTo($email->getReplyTo());
        $rc->addAttachments($email->getAttachments());
        $rc->setBody(Email::TEXT, $email->getBody(Email::TEXT));
        $rc->setBody(Email::HTML, $email->getBody(Email::HTML));
        
        if ($this->config->getMailMode() == EmailQueue::REROUTE) {
            $rc->setSubject($this->config->getRerouteConfig()->getSubjectPrefix().$this->config->getSubjectPrefix().$email->getSubject().' - '.$email->stringify($email->getTo()));
            $rc->addTo($this->config->getRerouteConfig()->getRecipients());
        } else {
            $rc->setSubject($this->config->getSubjectPrefix().$email->getSubject());
            $rc->addTo($email->getTo());
            $rc->addCc($email->getCc());
            $rc->addBcc($email->getBcc());
            if ($this->config->getMailMode() == EmailQueue::BCC) {
                $rc->addBcc($this->config->getBccConfig()->getRecipients());
            }
        }
        return $rc;
    }
    
    /**
     * Sends a single email or multiple emails.
     * @param mixed $email - single Email object or array of Email objects
     * @return TRUE when email was sent or number of emails sent successfully
     */
    public function send($email) {
        if (is_a($email, 'TgEmail\\Email')) {
            // Modify mail according to sending mode
            $email = $this->getReconfiguredEmail($email);
            return $this->_send($email);
        } else if (is_array($email)) {
            $sent = 0;
            foreach ($email AS $m) {
                if ($this->send($m)) $sent++;
            }
            return $sent;
        } else {
            throw new EmailException('Cannot send: $email must be array of Email or single Email object');
        }
    }
    
    /**
     * Synchronously send email object.
     */
    protected function _send(Email $email) {
        // Start
        if (!$this->fwam_canSendEmail($email)) {
            // repeat test 'cos its different entry
            return false;
        }
        //$phpMailer = $this->getMailer();
        $phpMailer = $this->fwam_getMailerForEmail($email);
        
        if (!$phpMailer->getSMTPInstance()->connected()) {
            // should be already connected, do not repeat connection and signal fail
            return false;
        }

        $debugSend = '';
        $oldDbgCfg = MailerWrapper::mailerDebugOutput($phpMailer, $debugSend); // enable debug output
        
        // Sender
        // if sender differs from ones provided in current smtp config, add reply-to and set sender as smtp default
        $emailSender = $email->getSender();
        $smtpSenders = $this->config->getSmtpConfig()->getSenders(); /* !!! ber nastavení z Wrapperu! Pokud by šlo o rozesílání z různých sad, byl by to problém (furt jeden odesilatel) */
        $found = false;
        foreach ($smtpSenders as $smtpSender) {
            if ($smtpSender->email === $emailSender->email) {
                $found = true;
                break;
            }
        }
        if ($found) {
            $phpMailer->setFrom($emailSender->email, $emailSender->name);
        } elseif (0) {
            // this form is not received by GMail (require valid SPF on From domain)
            $smtpSender = $smtpSenders[0];
            $phpMailer->Sender = $smtpSender->email; // set Sender manually
            $phpMailer->setFrom($emailSender->email, $emailSender->name, false); // noauto set Sender
        } elseif (1) {
            $smtpSender = $smtpSenders[0];
            $phpMailer->setFrom($smtpSender->email, $emailSender->name); // use SMTP address and EMAIL name
            $phpMailer->addReplyTo($emailSender->email, /*'Re: ' . */$emailSender->name); // add original address into Reply-To
        }
        
        // Reply-To
        if ($email->getReplyTo() != NULL) {
            $phpMailer->addReplyTo($email->getReplyTo()->email, $email->getReplyTo()->name);
        }
        
        // Recipients
        foreach ($email->getTo() as $recipient) {
            $phpMailer->addAddress($recipient->email, $recipient->name);
        }
        foreach ($email->getCc() as $recipient) {
            $phpMailer->addCC($recipient->email, $recipient->name);
        }
        foreach ($email->getBcc() as $recipient) {
            $phpMailer->addBCC($recipient->email, $recipient->name);
        }

        if (1) {
            // custom headers
            $phpMailer->addCustomHeader('Precedence', 'bulk');
            //$phpMailer->addCustomHeader('List-Unsubscribe', '???');
        }

        // Subject
        $phpMailer->Subject = $email->getSubject(); // PHPMailer should handle encoding itself
        
        // Body
        $bodyHTML = $email->getBody(Email::HTML);
        $bodyText = $email->getBody(Email::TEXT);
        if ($bodyHTML != NULL) {
            $phpMailer->msgHTML($bodyHTML, $this->config->getRootDir());
            if ($bodyText != NULL) {
                $phpMailer->AltBody = $bodyText; // overwrite translated HTML
            }
        } else {
            $phpMailer->Body = $bodyText;
        }
        //dumpe($phpMailer);
        
        // Attachments
        foreach ($email->getAttachments() as $a) {
            if ($a->type == Attachment::ATTACHED) {
                $phpMailer->addAttachment($a->path, $a->name, PHPMailer::ENCODING_BASE64, $a->mimeType);
            } else if ($a->type == 'embedded') {
                $phpMailer->addEmbeddedImage($a->path, $a->cid, $a->name);
            }
        }

        $rc = TRUE;
        if ($this->config->getMailMode() != EmailQueue::BLOCK) {
            //dumpe([$email, $phpMailer]);
            $rc = $phpMailer->send();
            Log::debug('Mail sent: '.$email->getLogString());
            if (!$rc) {
                Log::error("Mailer Error: " . $phpMailer->ErrorInfo);
            } else {
                $this->fwam_updateMailerProps($email);
                foreach ($email->getAttachments() as $a) {
                    if ($a->deleteAfterSent) {
                        unlink($a->path);
                    }
                }
            }
        }

        MailerWrapper::mailerDebugBasic($phpMailer, ...$oldDbgCfg); // return debug state back
        if (0 || !$rc) {
            // do something with log stored in $debugSend on failed send
            echo("<div><h5>Send [uid:$email->uid|$email->queued_time]</h5><textarea>$debugSend</textarea><br/>$phpMailer->ErrorInfo</div>");
            $phpMailer->ErrorInfo = ''; // clear error
        }

        return $rc;
    }

    /**
     * Queues a single email or multiple emails.
     * <p>The second parameter $recipients can be used with single Email object only.</p>
     * <p>Example of $recpients:</p>
     * <ul>
     * <li>list of list of recipients: <code>[ ["john.doe@example.com","john@example.com"], ["jane.doe@example.com"] ]</code></li>
     * <li>list of recipient objects: <code>[ {"to":"john.doe@example.com", "cc":"jane.doe@example.com"}, ... ]</code></li>
     * </ul>
     * @param mixed $email - single Email object or array of Email objects
     * @param array $recipients - list of recipients to send the same email. Can be a list of lists (TO addresses)
     *    or a list of objects with to, cc or bcc attributes that define the recipients.
     * @return TRUE when email was queued or number of emails queued successfully
     */
    public function queue($email, $recipients = NULL) {
        if (is_a($email, 'TgEmail\\Email')) {
            if ($recipients == NULL) {
                // Single Email to be sent
                // Modify mail according to sending mode
                $email = $this->getReconfiguredEmail($email);
                return $this->_queue($email);
            }
            // Single email with multiple recipient definitions
            $queued = 0;
            foreach ($recipients AS $def) {
                if (is_array($def)) {
                    // All TO addresses
                    $email->recipients = NULL;
                    $email->addTo($def);
                    if ($this->queue($email)) $queued++;
                } else {
                    $email->recipients = NULL;
                    if (isset($def->to))  $email->addTo($def->to);
                    if (isset($def->cc))  $email->addCc($def->cc);
                    if (isset($def->bcc)) $email->addBcc($def->bcc);
                    if ($this->queue($email)) $queued++;
                }
            }
            return $queued;
        } else if (is_array($email)) {
            $queued = 0;
            foreach ($email AS $m) {
                if ($this->queue($m)) $queued++;
            }
            return $queued;
        } else {
            throw new EmailException('Cannot queue: $email must be array of Email or single Email object');
        }
    }
    
    /**
     * Queues an email.
     *
     * @param Email $email
     *            - \WebApp\Email object
     * @return true when e-mail was queued
     */
    protected function _queue($email) {
        if ($this->mailDAO != NULL) {
            if ($this->config->getMailMode() != EmailQueue::BLOCK) {
                if (!$email->queued_time) {
                    $email->queued_time = new Date(time(), $this->config->getTimezone()); // FWAM do not rewrite, can be future
                }
                $email->status          = Email::PENDING;
                $email->failed_attempts = 0;
                $email->sent_time       = NULL;
                $rc = $this->mailDAO->create($email);
                return is_int($rc);
            }
            return TRUE;
        }
        throw new EmailException('Queueing is not supported. No DAO available.');
    }

}

