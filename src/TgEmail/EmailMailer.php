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
class EmailMailer
{

    /**
     * @var Config\SmtpConfig
     */
    protected $smtpConfig = null;

    /**
     * @var PHPMailer
     */
    protected $mailer = null;

    public function __construct(Config\SmtpConfig $smtpConfig)
    {
        $this->smtpConfig = $smtpConfig;
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
        $this::mailerDebugBasic($mailer, $smtpConfig->getDebugLevel() ?: SMTP::DEBUG_OFF); // init debug
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
        $oldDbgCfg = $this::mailerDebugOutput($mailer, $debugConnect);
        $bc = $mailer->smtpConnect(); // perform connect to log
        $this::mailerDebugBasic($mailer, ...$oldDbgCfg);
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
