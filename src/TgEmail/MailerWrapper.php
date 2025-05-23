<?php

/*
 *  René Panák (panak@advertising-media.cz)
 */

declare(strict_types=1);

namespace TgEmail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 *
 * @author rene
 */
class MailerWrapper
{

    /**
     * @var Config\SmtpConfig
     */
    protected $smtpConfig = null;

    /**
     * @var PHPMailer
     */
    protected $mailer = null;

    /**
     * @var string
     */
    protected $name = null;

    /**
     * @var string
     */
    protected $nameHash = null;

    /**
     * @var int
     */
    protected $sentLastMinute = 0;

    /**
     * @var int
     */
    protected $sentLastHour = 0;

    /**
     * @var int
     */
    protected $sentLastDay = 0;

    /**
     * @var bool
     */
    protected $flagLimitsRefreshed = false;

    /**
     * @var MailerWrapper
     */
    protected $alternativeWrapper = null;

    public function __construct(Config\SmtpConfig $smtpConfig)
    {
        $this->smtpConfig = $smtpConfig;
    }

    public function getName(): string
    {
        if (is_null($this->name)) {
            $host = $this->smtpConfig->getHost();
            $user = $this->smtpConfig->getUsername();
            $this->name = "$user@$host";
        }
        return $this->name;
    }

    public function getNameHash(): string
    {
        if (is_null($this->nameHash)) {
            $this->nameHash = md5($this->getName());
        }
        return $this->nameHash;
    }

    /**
     * @param EmailsDAO $dao
     * @return self
     */
    public function refreshLimits(EmailsDAO $dao, bool $force = false): self
    {
        if ($force || !$this->flagLimitsRefreshed) {
            $sent_via_cfg = $this->getNameHash();
            $this->sentLastMinute = $dao->getCountSentVia($sent_via_cfg, 'MINUTE');
            $this->sentLastHour = $dao->getCountSentVia($sent_via_cfg, 'HOUR');
            $this->sentLastDay = $dao->getCountSentVia($sent_via_cfg, 'DAY');
            $this->flagLimitsRefreshed = true;
        }
        return $this;
    }

    /**
     * @param MailerWrapper $wrapper
     * @return self
     */
    public function setAlternativeWrapper(MailerWrapper $wrapper): self
    {
        $this->alternativeWrapper = $wrapper;
        return $this;
    }

    /**
     * @return MailerWrapper
     * @throws MailerWrapperNotFoundException
     */
    public function getAlternativeWrapper(): MailerWrapper
    {
        if (!$this->alternativeWrapper) {
            throw new MailerWrapperNotFoundException('Alternative wrapper not set!');
        }
        return $this->alternativeWrapper;
    }

    public function setSent(): self
    {
        $this->sentLastMinute += 1;
        $this->sentLastHour += 1;
        $this->sentLastDay += 1;
        return $this;
    }

    public function canSendAnother(): bool
    {
        // ??? test SMTP connection??
        $smtpConfig = $this->smtpConfig;
        return 1 &&
                $this->sentLastMinute < $smtpConfig->limitMinute &&
                $this->sentLastHour < $smtpConfig->limitHour &&
                $this->sentLastDay < $smtpConfig->limitDay &&
                1;
    }

    /**
     * @param PHPMailer $mailer
     * @param int $level
     * @param string|callable $Debugoutput
     * @return array old values [$level, $Debugoutput]
     */
    public static function mailerDebugBasic(PHPMailer $mailer, int $level = SMTP::DEBUG_OFF,
            $Debugoutput = 'error_log'): array
    {
        $oldLevel = $mailer->SMTPDebug;
        $oldDebugoutput = $mailer->Debugoutput;

        $mailer->SMTPDebug = $level;
        $mailer->Debugoutput = $Debugoutput;
        $smtp = $mailer->getSMTPInstance();
        $smtp->setDebugLevel($mailer->SMTPDebug);
        $smtp->setDebugOutput($mailer->Debugoutput);

        return [
            $oldLevel,
            $oldDebugoutput
        ];
    }

    /**
     * @param PHPMailer $mailer
     * @return array old values [$level, $Debugoutput]
     */
    public static function mailerDebugOutput(PHPMailer $mailer, string &$output = ''): array
    {
        $old = static::mailerDebugBasic($mailer, SMTP::DEBUG_SERVER, function ($str, $level) use (&$output) {
                    //dump($str, $level);
                    $output .= $str . PHP_EOL;
                });
        return $old;
    }

    protected function initPhpMailerInstance()
    {
        $smtpConfig = $this->smtpConfig;
        $mailer = new PHPMailer();
        static::mailerDebugBasic($mailer, $smtpConfig->getDebugLevel() ?: SMTP::DEBUG_OFF); // init debug
        $mailer->isSMTP(); // telling the class to use SMTP
        $mailer->SMTPKeepAlive = true;
        $mailer->SMTPAuth = $smtpConfig->isAuth();
        $mailer->SMTPSecure = $smtpConfig->getSecureOption();
        $mailer->Port = $smtpConfig->getPort();
        $mailer->Host = $smtpConfig->getHost();
        $mailer->Username = $smtpConfig->getUsername();
        $mailer->Password = $smtpConfig->getPassword();
        $mailer->CharSet = $smtpConfig->getCharset();
        $mailer->Encoding = PHPMailer::ENCODING_BASE64; // from SmtpConfig???
        //$mailer->Encoding   = PHPMailer::ENCODING_QUOTED_PRINTABLE;

        $debugConnect = '';
        $oldDbgCfg = static::mailerDebugOutput($mailer, $debugConnect);
        $bc = $mailer->smtpConnect(); // perform connect to log
        static::mailerDebugBasic($mailer, ...$oldDbgCfg);
        if (0 || !$bc) {
            // do something with log stored in $debugConnect on failed connect
            echo("<div><h5>Connect</h5><textarea>$debugConnect</textarea><br/>{$mailer->ErrorInfo}</div>");
        }
        return $mailer;
    }

    public function getPhpMailerInstance(): PHPMailer
    {
        if (!$this->mailer) {
            $this->mailer = $this->initPhpMailerInstance();
        } else {
            $this->mailer->clearAllRecipients();
            $this->mailer->clearAttachments();
            $this->mailer->clearCustomHeaders();
            $this->mailer->clearReplyTos();
        }
        return $this->mailer;
    }

}

class MailerWrapperNotFoundException extends \Exception
{
    
}
