<?php

namespace Flowmailer\M2Connector\Plugin;

use Psr\Log\LoggerInterface;

use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Phrase;

use Flowmailer\M2Connector\Registry\MessageData;

use Flowmailer\M2Connector\Helper\API\FlowmailerAPI;
use Flowmailer\M2Connector\Helper\API\SubmitMessage;
use Flowmailer\M2Connector\Helper\API\Attachment;

class TransportPlugin
{
	/**
	 * @var Psr\Log\LoggerInterface
	 */
	protected $_logger;

	/**
	 * @var Magento\Framework\App\Config\ScopeConfigInterface
	 */
	protected $_scopeConfig;

	/**
	 */
	protected $_enabled;

	/**
	 */
	protected $_messageData;

	public function __construct(
		ScopeConfigInterface $scopeConfig,
		Manager		     $moduleManager,
		MessageData	     $messageData,
		LoggerInterface      $loggerInterface,
		EncryptorInterface   $encryptor
	) {
		$this->_scopeConfig = $scopeConfig;
		$this->_messageData = $messageData;
		$this->_logger      = $loggerInterface;
		$this->_encryptor   = $encryptor;

		$this->_logger->debug('[Flowmailer] messageData2 ' . spl_object_id($messageData));

		$this->_enabled = $this->_scopeConfig->isSetFlag('fmconnector/api_credentials/enable', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
		$this->_enabled = $this->_enabled && $moduleManager->isOutputEnabled('Flowmailer_M2Connector');
	}

	/**
	* Returns a string with the JSON request for the API from the current message
	*
	* @return string
	*/
	private function _getSubmitMessages(TransportInterface $transport) {

		$text	   = $transport->getMessage()->getBodyText(false);
		$html	   = $transport->getMessage()->getBodyHtml(false);

		if($text instanceof \Zend_Mime_Part) {
			$text = $text->getRawContent();
		}
		if($html instanceof \Zend_Mime_Part) {
			$html = $html->getRawContent();
		}

		$from = $transport->getMessage()->getFrom();
		if(null === $from) {
			$from = '';
		}

		$messages = array();
		foreach($transport->getMessage()->getRecipients() as $recipient) {
			$message = new SubmitMessage();

			$message->messageType = 'EMAIL';
			$message->senderAddress = $from;
			$message->headerFromAddress = $from;
			if(!empty($from_name)) {
				$message->headerFromName = $from_name;
			}
			$message->recipientAddress = trim($recipient);
			$message->subject = trim($transport->getMessage()->getSubject());
			$message->html = $html;
			$message->text = $text;
			$message->data = $this->_messageData->getTemplateVars();

			$attachments = array();
			$parts = $transport->getMessage()->getParts();
			foreach($parts as $part) {
				$attachment = new Attachment();
				$attachment->content = base64_encode($part->getRawContent());
				$attachment->contentType = $part->type;
				$attachment->filename = $part->filename;

				$attachments[] = $attachment;
			}
			$message->attachments = $attachments;

			$messages[] = $message;
		}
		return $messages;
	}

	/**
	* Returns a string with the JSON request for the API from the current message
	*
	* @return string
	*/
	private function _getSubmitMessagesZend2(TransportInterface $transport) {

		$raw = $transport->getMessage()->getRawMessage();
		$rawb64 = base64_encode($raw);

		$zendmessage = \Zend\Mail\Message::fromString($raw);

		if($zendmessage->getFrom()->count() > 0) {
			$from = $zendmessage->getFrom()->current()->getEmail();
		} else {
			$from = '';
		}

		$recipients = array();
		foreach($zendmessage->getTo() as $recipient) {
			$recipients[] = $recipient->getEmail();
		}
		foreach($zendmessage->getCc() as $recipient) {
			$recipients[] = $recipient->getEmail();
		}
		foreach($zendmessage->getBcc() as $recipient) {
			$recipients[] = $recipient->getEmail();
		}

		$messages = array();
		foreach($recipients as $recipient) {
			$message = new SubmitMessage();

			$message->messageType = 'EMAIL';
			$message->senderAddress = $from;
			$message->recipientAddress = trim($recipient);
			$message->mimedata = $rawb64;
			$message->data = $this->_messageData->getTemplateVars();

			$messages[] = $message;
		}
		return $messages;
	}

	public function aroundSendMessage(TransportInterface $subject, \Closure $proceed)
	{
		if($this->_enabled) {
			try {
				$this->_logger->debug('[Flowmailer] Sending message');
				if($subject->getMessage() instanceof \Zend_Mail) {
					$messages = $this->_getSubmitMessages($subject);
				} else {
					$messages = $this->_getSubmitMessagesZend2($subject);
				}

				$accountId = $this->_scopeConfig->getValue('fmconnector/api_credentials/api_account_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
				$apiId = $this->_scopeConfig->getValue('fmconnector/api_credentials/api_client_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
				$apiSecret = $this->_encryptor->decrypt($this->_scopeConfig->getValue('fmconnector/api_credentials/api_client_secret', \Magento\Store\Model\ScopeInterface::SCOPE_STORE));

				$api = new FlowmailerAPI($accountId, $apiId, $apiSecret);

				foreach($messages as $message) {
					$result = $api->submitMessage($message);

					if($result['headers']['ResponseCode'] != 201) {
						throw new \Exception(json_encode($result));
					}

					$this->_logger->debug('[Flowmailer] Sending message done ' . var_export($result, true));
				}
			} catch (\Exception $e) {
				$this->_logger->warn('[Flowmailer] Error sending message : ' . $e->getMessage());
				throw new MailException(new Phrase($e->getMessage()), $e);
			}
		} else {
			$this->_logger->debug('[Flowmailer] Module not enabled');
			return $proceed();
		}
	}
}

