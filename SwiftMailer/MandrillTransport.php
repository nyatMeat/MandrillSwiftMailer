<?php

namespace Accord\MandrillSwiftMailer\SwiftMailer;

use Mandrill;

use \Swift_Events_EventDispatcher;
use \Swift_Events_EventListener;
use \Swift_Events_SendEvent;
use \Swift_Mime_SimpleMessage;
use \Swift_Transport;
use \Swift_Attachment;
use \Swift_MimePart;
use Psr\Log\LoggerInterface;

class MandrillTransport implements Swift_Transport
{

    /**
     * @type Swift_Events_EventDispatcher
     */
    protected $dispatcher;

    /**
     * @var string|null
     */
    protected $apiKey;

    /**
     * @var bool|null
     */
    protected $async;

    /**
     * @var array|null
     */
    protected $resultApi;

    /**
     * @var string|null
     */
    protected $subAccount;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Swift_Events_EventDispatcher $dispatcher
     */
    public function __construct(Swift_Events_EventDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
        $this->apiKey = null;
        $this->async = null;
        $this->subAccount = null;
    }

    /**
     * Not used
     */
    public function isStarted()
    {
        return false;
    }

    /**
     * Not used
     */
    public function start()
    {
    }

    /**
     * Not used
     */
    public function stop()
    {
    }

    /**
     * Not used
     */
    public function ping()
    {
    }

    /**
     * @param string $apiKey
     * @return $this
     */
    public function setApiKey($apiKey)
    {
        $this->apiKey = $apiKey;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getApiKey()
    {
        return $this->apiKey;
    }

    /**
     * @param bool $async
     * @return $this
     */
    public function setAsync($async)
    {
        $this->async = $async;
        return $this;
    }

    /**
     * @return null|bool
     */
    public function getAsync()
    {
        return $this->async;
    }


    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getLogger()
    {
        return $this->logger;
    }


    /**
     * @param null|string $subAccount
     * @return $this
     */
    public function setSubAccount($subAccount)
    {
        $this->subAccount = $subAccount;
        return $this;
    }

    /**
     * @return null|string
     */
    public function getSubAccount()
    {
        return $this->subAccount;
    }

    /**
     * @return Mandrill
     * @throws \Swift_TransportException
     */
    protected function createMandrill()
    {
        if ($this->apiKey === null) {
            throw new \Swift_TransportException('Cannot create instance of \Mandrill while API key is NULL');
        }
        return new Mandrill($this->apiKey);
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @param null $failedRecipients
     * @return int Number of messages sent
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        if ($this->getLogger()) {
            $this->getLogger()->info(self::class, [
                'message' => 'Init sending message via mandrill',
                'subject' => $message->getSubject(),
                'sender' => $message->getSender(),
                'from' => $message->getFrom(),
                'cc' => $message->getCc(),
                'to' => $message->getTo()
            ]);
        }

        $this->resultApi = null;
        if ($event = $this->dispatcher->createSendEvent($this, $message)) {
            $this->getLogger()->info(self::class, [
                'message' => 'Something went wrong, message is not sent',
                'subject' => $message->getSubject(),
                'sender' => $message->getSender(),
                'from' => $message->getFrom(),
                'cc' => $message->getCc(),
                'to' => $message->getTo()
            ]);
            $this->dispatcher->dispatchEvent($event, 'beforeSendPerformed');
            if ($event->bubbleCancelled()) {
                return 0;
            }
        }

        $sendCount = 0;

        $mandrillMessage = $this->getMandrillMessage($message);

        $mandrill = $this->createMandrill();

        $this->resultApi = $mandrill->messages->send($mandrillMessage, $this->async);

        foreach ($this->resultApi as $item) {
            $this->getLogger()->info(self::class, [
                'message' => 'Something went wrong, message is not sent',
                'subject' => $message->getSubject(),
                'sender' => $message->getSender(),
                'from' => $message->getFrom(),
                'cc' => $message->getCc(),
                'to' => $message->getTo(),
                'mandrilApiInfo' => $item,
            ]);
            if ($item['status'] === 'sent' || $item['status'] === 'queued') {
                $sendCount++;
            } else {
                $failedRecipients[] = $item['email'];
            }
        }

        if ($this->getLogger()) {
            $this->getLogger()->info(self::class, [
                'message' => 'Something went wrong, message is not sent',
                'subject' => $message->getSubject(),
                'sender' => $message->getSender(),
                'from' => $message->getFrom(),
                'cc' => $message->getCc(),
                'to' => $message->getTo(),
                'countSent' => 0,
                'failedEmails' => $failedRecipients
            ]);
        }
        if ($event) {
            if ($sendCount > 0) {
                $event->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
            } else {
                $event->setResult(Swift_Events_SendEvent::RESULT_FAILED);
            }

            $this->dispatcher->dispatchEvent($event, 'sendPerformed');
        }

        return $sendCount;
    }

    /**
     * @param Swift_Events_EventListener $plugin
     */
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        $this->dispatcher->bindEventListener($plugin);
    }

    /**
     * @return array
     */
    protected function getSupportedContentTypes()
    {
        return array(
            'text/plain',
            'text/html'
        );
    }

    /**
     * @param string $contentType
     * @return bool
     */
    protected function supportsContentType($contentType)
    {
        return in_array($contentType, $this->getSupportedContentTypes());
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @return string
     */
    protected function getMessagePrimaryContentType(Swift_Mime_SimpleMessage $message)
    {
        $contentType = $message->getContentType();

        if ($this->supportsContentType($contentType)) {
            return $contentType;
        }

        // SwiftMailer hides the content type set in the constructor of Swift_Mime_SimpleMessage as soon
        // as you add another part to the message. We need to access the protected property
        // userContentType to get the original type.
        $messageRef = new \ReflectionClass($message);
        if ($messageRef->hasProperty('userContentType')) {
            $propRef = $messageRef->getProperty('userContentType');
            $propRef->setAccessible(true);
            $contentType = $propRef->getValue($message);
        }

        return $contentType;
    }

    /**
     * https://mandrillapp.com/api/docs/messages.php.html#method-send
     *
     * @param Swift_Mime_SimpleMessage $message
     * @return array Mandrill Send Message
     * @throws \Swift_SwiftException
     */
    public function getMandrillMessage(Swift_Mime_SimpleMessage $message)
    {
        $contentType = $this->getMessagePrimaryContentType($message);

        $fromAddresses = $message->getFrom();
        $fromEmails = array_keys($fromAddresses);

        $toAddresses = $message->getTo();
        $ccAddresses = $message->getCc() ? $message->getCc() : [];
        $bccAddresses = $message->getBcc() ? $message->getBcc() : [];
        $replyToAddresses = $message->getReplyTo() ? $message->getReplyTo() : [];

        $to = array();
        $attachments = array();
        $images = array();
        $headers = array();
        $tags = array();

        foreach ($toAddresses as $toEmail => $toName) {
            $to[] = array(
                'email' => $toEmail,
                'name' => $toName,
                'type' => 'to'
            );
        }

        foreach ($replyToAddresses as $replyToEmail => $replyToName) {
            if ($replyToName) {
                $headers['Reply-To'] = sprintf('%s <%s>', $replyToEmail, $replyToName);
            } else {
                $headers['Reply-To'] = $replyToEmail;
            }
        }

        foreach ($ccAddresses as $ccEmail => $ccName) {
            $to[] = array(
                'email' => $ccEmail,
                'name' => $ccName,
                'type' => 'cc'
            );
        }

        foreach ($bccAddresses as $bccEmail => $bccName) {
            $to[] = array(
                'email' => $bccEmail,
                'name' => $bccName,
                'type' => 'bcc'
            );
        }

        $bodyHtml = $bodyText = null;

        if ($contentType === 'text/plain') {
            $bodyText = $message->getBody();
        } elseif ($contentType === 'text/html') {
            $bodyHtml = $message->getBody();
        } else {
            $bodyHtml = $message->getBody();
        }

        foreach ($message->getChildren() as $child) {
            if ($child instanceof \Swift_Image) {
                $images[] = array(
                    'type' => $child->getContentType(),
                    'name' => $child->getId(),
                    'content' => base64_encode($child->getBody()),
                );
            } elseif ($child instanceof Swift_Attachment && !($child instanceof \Swift_Image)) {
                $attachments[] = array(
                    'type' => $child->getContentType(),
                    'name' => $child->getFilename(),
                    'content' => base64_encode($child->getBody())
                );
            } elseif ($child instanceof Swift_MimePart && $this->supportsContentType($child->getContentType())) {
                if ($child->getContentType() == "text/html") {
                    $bodyHtml = $child->getBody();
                } elseif ($child->getContentType() == "text/plain") {
                    $bodyText = $child->getBody();
                }
            }
        }

        $mandrillMessage = array(
            'html' => $bodyHtml,
            'text' => $bodyText,
            'subject' => $message->getSubject(),
            'from_email' => $fromEmails[0],
            'from_name' => $fromAddresses[$fromEmails[0]],
            'to' => $to,
            'headers' => $headers,
            'tags' => $tags,
            'inline_css' => null
        );

        if (count($attachments) > 0) {
            $mandrillMessage['attachments'] = $attachments;
        }

        if (count($images) > 0) {
            $mandrillMessage['images'] = $images;
        }

        foreach ($message->getHeaders()->getAll() as $header) {
            if ($header->getFieldType() === \Swift_Mime_Header::TYPE_TEXT) {
                switch ($header->getFieldName()) {
                    case 'List-Unsubscribe':
                        $headers['List-Unsubscribe'] = $header->getValue();
                        $mandrillMessage['headers'] = $headers;
                        break;
                    case 'X-MC-InlineCSS':
                        $mandrillMessage['inline_css'] = $header->getValue();
                        break;
                    case 'X-MC-Tags':
                        $tags = $header->getValue();
                        if (!is_array($tags)) {
                            $tags = explode(',', $tags);
                        }
                        $mandrillMessage['tags'] = $tags;
                        break;
                    case 'X-MC-Autotext':
                        $autoText = $header->getValue();
                        if (in_array($autoText, array('true', 'on', 'yes', 'y', true), true)) {
                            $mandrillMessage['auto_text'] = true;
                        }
                        if (in_array($autoText, array('false', 'off', 'no', 'n', false), true)) {
                            $mandrillMessage['auto_text'] = false;
                        }
                        break;
                    case 'X-MC-GoogleAnalytics':
                        $analyticsDomains = explode(',', $header->getValue());
                        if (is_array($analyticsDomains)) {
                            $mandrillMessage['google_analytics_domains'] = $analyticsDomains;
                        }
                        break;
                    case 'X-MC-GoogleAnalyticsCampaign':
                        $mandrillMessage['google_analytics_campaign'] = $header->getValue();
                        break;
                    case 'X-MC-TrackingDomain':
                        $mandrillMessage['tracking_domain'] = $header->getValue();
                        break;
                    default:
                        if (strncmp($header->getFieldName(), 'X-', 2) === 0) {
                            $headers[$header->getFieldName()] = $header->getValue();
                            $mandrillMessage['headers'] = $headers;
                        }
                        break;
                }
            }
        }

        if ($this->getSubaccount()) {
            $mandrillMessage['subaccount'] = $this->getSubaccount();
        }

        return $mandrillMessage;
    }

    /**
     * @return null|array
     */
    public function getResultApi()
    {
        return $this->resultApi;
    }
}
