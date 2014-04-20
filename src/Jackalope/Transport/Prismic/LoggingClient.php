<?php

namespace Jackalope\Transport\Prismic;

use Jackalope\FactoryInterface;
use Jackalope\Transport\AbstractReadLoggingWrapper;

use Jackalope\Transport\QueryInterface as QueryTransport;

use Jackalope\Query\Query;

use Jackalope\Transport\Logging\LoggerInterface;

/**
 * Logging enabled wrapper for the Jackalope Jackrabbit client.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 */

class LoggingClient extends AbstractReadLoggingWrapper implements QueryTransport
{
    /**
     * @var Client
     */
    protected $transport;

    /**
     * Constructor.
     *
     * @param FactoryInterface $factory
     * @param Client           $transport A jackalope jackrabbit client instance
     * @param LoggerInterface  $logger    A logger instance
     */
    public function __construct(FactoryInterface $factory, Client $transport, LoggerInterface $logger)
    {
        parent::__construct($factory, $transport, $logger);
    }

    /**
     * @return Api
     */
    public function getApi()
    {
        return $this->transport->getApi();
    }

    /**
     * @return Ref
     */
    public function getRef()
    {
        return $this->transport->getRef();
    }

    /**
     * @param Ref $ref
     */
    public function setRef($ref)
    {
        $this->transport->setRef($ref);
    }

    /**
     * @return string
     */
    public function getAccessToken()
    {
        return $this->transport->getAccessToken();
    }

    /**
     * @param string $accessToken
     */
    public function setAccessToken($accessToken)
    {
        $this->transport->setAccessToken($accessToken);
    }

    /**
     * @param string $defaultWorkspaceName
     */
    public function setDefaultWorkspaceName($defaultWorkspaceName)
    {
        $this->transport->setDefaultWorkspaceName($defaultWorkspaceName);
    }

    /**
     * Configure whether to check if we are logged in before doing a request.
     *
     * Will improve error reporting at the cost of some round trips.
     */
    public function setCheckLoginOnServer($bool)
    {
        $this->transport->setCheckLoginOnServer($bool);
    }

    /**
     * {@inheritDoc}
     */
    public function query(Query $query)
    {
        $this->logger->startCall(__FUNCTION__, func_get_args(), array('fetchDepth' => $this->transport->getFetchDepth()));
        $result = $this->transport->query($query);
        $this->logger->stopCall();
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getSupportedQueryLanguages()
    {
        return $this->transport->getSupportedQueryLanguages();
    }
}
