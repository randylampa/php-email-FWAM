<?php

namespace TgEmail;

use TgDatabase\DAO;
use TgDatabase\Restrictions;
use TgDatabase\Order;

/**
 * The DAO for Email objects
 * @author ralph
 *        
 */
class EmailsDAO extends DAO {

    /**
     */
    public function __construct($database, $tableName = '#__mail_queue', $modelClass = 'TgEmail\\Email', $idColumn = 'uid', $checkTable = FALSE) {
        parent::__construct($database, $tableName, $modelClass, $idColumn, $checkTable);
    }

	/**
	 * Implements the method from base class.
	 * @return boolean TRUE when table could be created. An exception is thrown when the method fails.
	 */
    public function createTable() {
		// Create it (try)
		$sql =
			'CREATE TABLE `'.$this->tableName.'` ( '.
				'`'.$this->idColumn.'`  INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'ID of queue element\', '.
				'`sender`          VARCHAR(200) NOT NULL COMMENT \'sender address\', '.
				'`sent_via_cfg`    VARCHAR(35)  DEFAULT NULL COMMENT \'which sender account configuration was used (mostly MD5 hash)\', '.
				'`reply_to`        VARCHAR(200) NULL COMMENT \'Reply-To address\', '.
				'`recipients`      TEXT         COLLATE utf8mb4_bin NOT NULL COMMENT \'email recipients\', '.
				'`subject`         VARCHAR(200) NOT NULL COMMENT \'email subject\', '.
				'`unsub_link`      VARCHAR(255) NULL COMMENT \'List-Unsubscribe URL\', '.
				'`body`            MEDIUMTEXT   COLLATE utf8mb4_bin NOT NULL COMMENT \'email bodies\', '.
				'`attachments`     TEXT         COLLATE utf8mb4_bin NOT NULL COMMENT \'attachment data\', '.
				'`queued_time`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'Time the email was queued\', '. // FWAM auto CURRENT_TIMESTAMP, can be future
				'`priority`        INT(11)      NOT NULL DEFAULT 0 COMMENT \'Priority of mail\', '. // FWAM
				'`update_time`     DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT \'Time the email was updated\', '. // FWAM time of last action
				'`status`          ENUM(\'pending\',\'processing\',\'sent\',\'failed\') NOT NULL COMMENT \'email status\', '. // Email::PENDING ...
				'`sent_time`       DATETIME     NULL COMMENT \'Time the email was sent successfully\', '.
				'`failed_attempts` TINYINT(2)   UNSIGNED NOT NULL DEFAULT 0 COMMENT \'Number of failed sending attempts\', '.
				'PRIMARY KEY (`'.$this->idColumn.'`), '.
				'KEY `idx_status` (`status`), '.
				'KEY `idx_priority_queued_time` (`priority`,`queued_time`), '.
				'KEY `idx_sent_via_cfg` (`sent_via_cfg`) '.
			') ENGINE = InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT = \'Email Queue\'';
		
		$res = $this->database->query($sql);
		if ($res === FALSE) {
			throw new EmailException('Cannot create table '.$this->tableName.': '.$this->database->error());
		}
        return TRUE;
    }
    
	/**
     * remove property update_time, 'cos DB sets CURRENT_TIMESTAMP on update
	 * @param object $object - the object to be persisted
	 * @param boolean $isCreate - TRUE when this is a new object to be created
	 * @return object the actual object to be persisted
	 */
    protected function preSave($object, $isCreate) {
        $object2 = parent::preSave($object, $isCreate);
        if (!$isCreate) {
            unset($object2->update_time);
        }
        return $object2;
    }
    
    public function housekeeping($maxSentDays = 90, $maxFailedDays = 180) {
        $this->database->delete($this->tableName, array(Restrictions::eq('status', 'sent'),  Restrictions::sql('TIMESTAMPDIFF(DAY, sent_time, NOW()) >= '.$maxSentDays)));
        $this->database->delete($this->tableName, array(Restrictions::eq('status','failed'), Restrictions::sql('TIMESTAMPDIFF(DAY, sent_time, NOW()) >= '.$maxFailedDays)));
    }
    
    public function getPendingEmails() {
        return $this->getEmailsByStatus(Email::PENDING, Order::asc('queued_time'));
    }

    public function getFailedEmails() {
        return $this->getEmailsByStatus(Email::FAILED);
    }

	public function getEmailsByStatus($status, $order = NULL) {
        return $this->find(Restrictions::eq('status', $status), $order);
	}

    public function getPendingEmailUids(int $maxObjects = 0) {
        return $this->findUids(
                        [Restrictions::eq('status', Email::PENDING), Restrictions::sql('queued_time <= NOW()')],
                        [Order::desc('priority'), Order::asc('queued_time'), Order::asc($this->idColumn)],
                        0,
                        $maxObjects
        );
    }

    /**
     * TIMESTAMPDIFF
     * MINUTE
     * HOUR
     * DAY
     * @param string $sent_via_cfg
     * @param string $interval
     * @return int
     */
    public function getCountSentVia(string $sent_via_cfg, string $interval): int
    {
        $a = ['MINUTE', 'HOUR', 'DAY'];
        if (!in_array($interval, $a)) {
            return null;
        }
        return $this->count([
                    //Restrictions::eq('status', Email::SENT)
                    Restrictions::eq('sent_via_cfg', $sent_via_cfg),
                    Restrictions::isNotNull('sent_time'),
                    Restrictions::sql("TIMESTAMPDIFF($interval, sent_time, CURRENT_TIMESTAMP) < 1 "),
        ]);
    }

}

