<?php

namespace TgEmail;

use PHPUnit\Framework\TestCase;
use TgDatabase\Database;
use TgLog\Log;

/**
 * Tests the EmailQueue.
 * <p>This class requires enviroment variable EMAIL_DATABASE and EMAIL_TEST_SMTP
 *    to contain the configuration for the database access and SMTP parameters
 *    to successfully test the sending and queuing.</p>
 * @author ralph
 *        
 */
class EmailQueueTest extends TestCase {

    private static $database    = NULL;
    private static $dao         = NULL;
    private static $queue       = NULL;
    private static $queueConfig = NULL;
    
    public function testSendWithBlocked(): void {
        if (getenv('EMAIL_TEST_SMTP') != NULL) {
            $config = json_decode(getenv('EMAIL_TEST_SMTP'));
            $queue  = self::getEmailQueue(EmailQueue::BLOCK);
            $email  = new Email();
            $email->setSubject('[EmailQueueTest] testSendWithBlocked');
            $email->setBody(Email::HTML, '<h1>EmailQueueTest::testSendWithBlocked</h1><p>This email shall not have been delivered! Test failed.</p>');
            $email->setBody(Email::TEXT, "EmailQueueTest::testSendWithBlocked\n=================\n\nThis email shall not have been delivered! Test failed.\n");
            $email->addTo(EmailAddress::from($config->targetAddress));
            if (isset($config->replyToAddress)) $email->setReplyTo(EmailAddress::from($config->replyToAddress));
            
            $this->assertTrue($queue->send($email));
        } else {
            // Just to not create a test warning
            $this->assertTrue(TRUE);
        }
    }
    
    public function testSendWithReroute(): void {
        if (getenv('EMAIL_TEST_SMTP') != NULL) {
            $config = json_decode(getenv('EMAIL_TEST_SMTP'));
            $queue  = self::getEmailQueue(EmailQueue::REROUTE);
            $email  = new Email();
            $email->setSubject('testSendWithReroute');
            $email->setBody(Email::HTML, '<h1>EmailQueueTest::testSendWithReroute</h1><p>This email shall have arrived at your reroute mailbox: '.self::$queueConfig->getRerouteConfig()->getRecipients()[0]->email.'</p>');
            $email->setBody(Email::TEXT, "EmailQueueTest::testSendWithReroute\n=================\n\nThis email shall have arrived at your reroute mailbox: ".self::$queueConfig->getRerouteConfig()->getRecipients()[0]->email."\n");
            $email->addTo(EmailAddress::from($config->targetAddress));
            if (isset($config->replyToAddress)) $email->setReplyTo(EmailAddress::from($config->replyToAddress));
            
            $this->assertTrue($queue->send($email));
        } else {
            // Just to not create a test warning
            $this->assertTrue(TRUE);
        }
    }
    
    public function testSendWithBcc(): void {
        if (getenv('EMAIL_TEST_SMTP') != NULL) {
            $config = json_decode(getenv('EMAIL_TEST_SMTP'));
            $queue  = self::getEmailQueue(EmailQueue::BCC);
            $email  = new Email();
            $email->setSubject('testSendWithBcc');
            $email->setBody(Email::HTML, '<h1>EmailQueueTest::testSendWithBcc</h1><p>This email shall have arrived at your BCC mailbox: '.self::$queueConfig->getBccConfig()->getRecipients()[0]->email.'</p>');
            $email->setBody(Email::TEXT, "EmailQueueTest::testSendWithBcc\n=================\n\nThis email shall have arrived at your target mailbox: ".self::$queueConfig->getBccConfig()->getRecipients()[0]->email."\n");
            $email->addTo(EmailAddress::from($config->targetAddress));
            if (isset($config->replyToAddress)) $email->setReplyTo(EmailAddress::from($config->replyToAddress));
            
            $this->assertTrue($queue->send($email));
        } else {
            // Just to not create a test warning
            $this->assertTrue(TRUE);
        }
    }
    
    public function testSendWithDefault(): void {
        if (getenv('EMAIL_TEST_SMTP') != NULL) {
            $config = json_decode(getenv('EMAIL_TEST_SMTP'));
            $queue  = self::getEmailQueue(EmailQueue::DEFAULT);
            $email  = new Email();
            $email->setSubject('testSendWithDefault');
            $email->setBody(Email::HTML, '<h1>EmailQueueTest::testSendWithReroute</h1><p>This email shall have arrived at your normal mailbox: '.$config->targetAddress.'</p>');
            $email->setBody(Email::TEXT, "EmailQueueTest::testSendWithReroute\n=================\n\nThis email shall have arrived at your target mailbox: ".$config->targetAddress."\n");
            $email->addTo(EmailAddress::from($config->targetAddress));
            if (isset($config->replyToAddress)) $email->setReplyTo(EmailAddress::from($config->replyToAddress));
            
            $this->assertTrue($queue->send($email));
        } else {
            // Just to not create a test warning
            $this->assertTrue(TRUE);
        }
    }
    
    public function testQueueWithBlocked(): void {
        if ((getenv('EMAIL_TEST_SMTP') != NULL) && (getenv('EMAIL_DATABASE') != NULL)) {
            $config = json_decode(getenv('EMAIL_TEST_SMTP'));
            $queue  = self::getEmailQueue(EmailQueue::BLOCK);
            $email  = new Email();
            $email->setSubject('[EmailQueueTest] testQueueWithBlocked');
            $email->setBody(Email::HTML, '<h1>EmailQueueTest::testQueueWithBlocked</h1><p>This email shall not have been delivered! Test failed.</p>');
            $email->setBody(Email::TEXT, "EmailQueueTest::testQueueWithBlocked\n=================\n\nThis email shall not have been delivered! Test failed.\n");
            $email->addTo(EmailAddress::from($config->targetAddress));
            if (isset($config->replyToAddress)) $email->setReplyTo(EmailAddress::from($config->replyToAddress));
            
            $this->assertTrue($rc = $queue->queue($email));
            
            // Now process the queue
            $rc = $queue->processQueue();
            $this->assertTrue($rc->sent >= 0);
        } else {
            // Just to not create a test warning
            $this->assertTrue(TRUE);
        }
    }
    
    public function testQueueWithReroute(): void {
        if ((getenv('EMAIL_TEST_SMTP') != NULL) && (getenv('EMAIL_DATABASE') != NULL)) {
            $config = json_decode(getenv('EMAIL_TEST_SMTP'));
            $queue  = self::getEmailQueue(EmailQueue::REROUTE);
            $email  = new Email();
            $email->setSubject('testQueueWithReroute');
            $email->setBody(Email::HTML, '<h1>EmailQueueTest::testQueueWithReroute</h1><p>This email shall have arrived at your reroute mailbox: '.self::$queueConfig->getRerouteConfig()->getRecipients()[0]->email.'</p>');
            $email->setBody(Email::TEXT, "EmailQueueTest::testQueueWithReroute\n=================\n\nThis email shall have arrived at your reroute mailbox: ".self::$queueConfig->getRerouteConfig()->getRecipients()[0]->email."\n");
            $email->addTo(EmailAddress::from($config->targetAddress));
            if (isset($config->replyToAddress)) $email->setReplyTo(EmailAddress::from($config->replyToAddress));
            
            $this->assertTrue($rc = $queue->queue($email));
            
            // Now process the queue
            $rc = $queue->processQueue();
            $this->assertTrue($rc->sent >= 1);
        } else {
            // Just to not create a test warning
            $this->assertTrue(TRUE);
        }
    }
    
    public function testQueueWithBcc(): void {
        if ((getenv('EMAIL_TEST_SMTP') != NULL) && (getenv('EMAIL_DATABASE') != NULL)) {
            $config = json_decode(getenv('EMAIL_TEST_SMTP'));
            $queue  = self::getEmailQueue(EmailQueue::BCC);
            $email  = new Email();
            $email->setSubject('testQueueWithBcc');
            $email->setBody(Email::HTML, '<h1>EmailQueueTest::testQueueWithBcc</h1><p>This email shall have arrived at your BCC mailbox: '.self::$queueConfig->getBccConfig()->getRecipients()[0]->email.'</p>');
            $email->setBody(Email::TEXT, "EmailQueueTest::testQueueWithBcc\n=================\n\nThis email shall have arrived at your target mailbox: ".self::$queueConfig->getBccConfig()->getRecipients()[0]->email."\n");
            $email->addTo(EmailAddress::from($config->targetAddress));
            if (isset($config->replyToAddress)) $email->setReplyTo(EmailAddress::from($config->replyToAddress));
            
            $this->assertTrue($rc = $queue->queue($email));
            
            // Now process the queue
            $rc = $queue->processQueue();
            $this->assertTrue($rc->sent >= 1);
        } else {
            // Just to not create a test warning
            $this->assertTrue(TRUE);
        }
    }
    
    public function testQueueWithDefault(): void {
        if ((getenv('EMAIL_TEST_SMTP') != NULL) && (getenv('EMAIL_DATABASE') != NULL)) {
            $config = json_decode(getenv('EMAIL_TEST_SMTP'));
            $queue  = self::getEmailQueue(EmailQueue::DEFAULT);
            $email  = new Email();
            $email->setSubject('testQueueWithDefault');
            $email->setBody(Email::HTML, '<h1>EmailQueueTest::testQueueWithReroute</h1><p>This email shall have arrived at your normal mailbox: '.$config->targetAddress.'</p>');
            $email->setBody(Email::TEXT, "EmailQueueTest::testQueueWithReroute\n=================\n\nThis email shall have arrived at your target mailbox: ".$config->targetAddress."\n");
            $email->addTo(EmailAddress::from($config->targetAddress));
            if (isset($config->replyToAddress)) $email->setReplyTo(EmailAddress::from($config->replyToAddress));
            
            $this->assertTrue($rc = $queue->queue($email));
            
            // Now process the queue
            $rc = $queue->processQueue();
            $this->assertTrue($rc->sent >= 1);
        } else {
            // Just to not create a test warning
            $this->assertTrue(TRUE);
        }
    }

    public static function getMailDAO() {
        Log::setDefaultLogLevel(Log::ERROR);
        if ((self::$database == NULL) && (getenv('EMAIL_DATABASE') != NULL)) {
            $config         = json_decode(getenv('EMAIL_DATABASE'), TRUE);
            self::$database = new Database($config);
            self::$dao      = new EmailsDAO(self::$database);
            self::$dao->deleteBy();
        }
        return self::$dao;
    }
    
    public static function getEmailQueue($mailMode) {
        if ((self::$queue == NULL) && getenv('EMAIL_TEST_SMTP') != NULL) {
            self::$queueConfig = EmailConfig::from(getenv('EMAIL_TEST_SMTP'));
            self::$queue       = new EmailQueue(self::$queueConfig, self::getMailDAO());
        }
        if (self::$queueConfig != NULL) {
            self::$queueConfig->setMailMode($mailMode);
        }
        return self::$queue;
    }
    
}

