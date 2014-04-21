<?php

namespace Jackalope;

use PHPCR\RepositoryFactoryInterface;

/**
 * This factory creates repositories with the Prismic transport
 *
 * Use repository factory based on parameters (the parameters below are examples):
 *
 * <pre>
 *    $parameters = array('jackalope.prismic_uri' => 'https://%s.prismic.io/api');
 *    $factory = new \Jackalope\RepositoryFactoryPrismic;
 *    $repo = $factory->getRepository($parameters);
 * </pre>
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @api
 */
class RepositoryFactoryPrismic implements RepositoryFactoryInterface
{
    /**
     * list of required parameters for Prismic
     * @var array
     */
    private static $required = array(
        'jackalope.prismic_uri' => 'string (required): Path to the prismic server (with a %s placeholder for the workspace name',
    );

    /**
     * list of optional parameters for Prismic
     * @var array
     */
    private static $optional = array(
        'jackalope.prismic_access_token' => 'string: An access token, if none is supplied, username/password must be supplied via the credentials',
        'jackalope.prismic_default_workspace' => 'string: Name of the default workspace',
        'jackalope.factory' => 'string or object: Use a custom factory class for Jackalope objects',
        'jackalope.check_login_on_server' => 'boolean: if set to empty or false, skip initial check whether repository exists. Enabled by default, disable to gain a few milliseconds off each repository instantiation.',
        'jackalope.disable_stream_wrapper' => 'boolean: if set and not empty, stream wrapper is disabled, otherwise the stream wrapper is enabled and streams are only fetched when reading from for the first time. If your code always uses all binary properties it reads, you can disable this for a small performance gain.',
        'jackalope.logger' => 'Psr\Log\LoggerInterface: Use the LoggingClient to wrap the default transport Client',
        Session::OPTION_AUTO_LASTMODIFIED => 'boolean: Whether to automatically update nodes having mix:lastModified. Defaults to true.',
    );

    /**
     * Get a repository connected to the Prismic backend specified in the
     * parameters.
     *
     * {@inheritDoc}
     *
     * Prismic repositories have no default repository, passing null as
     * parameters will always return null.
     *
     * @api
     */
    public function getRepository(array $parameters = null)
    {
        if (null === $parameters) {
            return null;
        }

        // check if we have all required keys
        $present = array_intersect_key(self::$required, $parameters);
        if (count(array_diff_key(self::$required, $present))) {
            return null;
        }
        $defined = array_intersect_key(array_merge(self::$required, self::$optional), $parameters);
        if (count(array_diff_key($defined, $parameters))) {
            return null;
        }

        if (isset($parameters['jackalope.factory'])) {
            $factory = $parameters['jackalope.factory'] instanceof FactoryInterface
                ? $parameters['jackalope.factory'] : new $parameters['jackalope.factory'];
        } else {
            $factory = new Factory();
        }

        $transport = $factory->get('Transport\Prismic\Client', array($parameters['jackalope.prismic_uri']));
        if (isset($parameters['jackalope.prismic_access_token'])) {
            $transport->setAccessToken($parameters['prismic_access_token']);
        }
        if (isset($parameters['jackalope.prismic_access_token'])) {
            $transport->setDefaultWorkspaceName($parameters['prismic_access_token']);
        }
        if (isset($parameters['jackalope.check_login_on_server'])) {
            $transport->setCheckLoginOnServer($parameters['jackalope.check_login_on_server']);
        }
        if (isset($parameters['jackalope.logger'])) {
            $transport = $factory->get('Transport\Prismic\LoggingClient', array($transport, $parameters['jackalope.logger']));
        }

        $options['stream_wrapper'] = empty($parameters['jackalope.disable_stream_wrapper']);
        if (isset($parameters[Session::OPTION_AUTO_LASTMODIFIED])) {
            $options[Session::OPTION_AUTO_LASTMODIFIED] = $parameters[Session::OPTION_AUTO_LASTMODIFIED];
        }

        return new Repository($factory, $transport, $options);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getConfigurationKeys()
    {
        return array_merge(self::$required, self::$optional);
    }
}
