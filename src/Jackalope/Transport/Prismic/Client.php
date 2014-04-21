<?php

namespace Jackalope\Transport\Prismic;

use PHPCR\PropertyType;
use PHPCR\RepositoryInterface;
use PHPCR\NamespaceRegistryInterface;
use PHPCR\CredentialsInterface;
use PHPCR\Query\QOM\QueryObjectModelInterface;
use PHPCR\Query\QueryInterface;
use PHPCR\RepositoryException;
use PHPCR\NoSuchWorkspaceException;
use PHPCR\ItemNotFoundException;
use PHPCR\SimpleCredentials;
use PHPCR\Query\InvalidQueryException;

use PHPCR\Util\QOM\Sql2ToQomQueryConverter;
use PHPCR\Util\PathHelper;

use Jackalope\Query\Query;
use Jackalope\Transport\BaseTransport;
use Jackalope\Transport\QueryInterface as QueryTransport;
use Jackalope\Transport\StandardNodeTypes;
use Jackalope\Transport\Prismic\Query\QOMWalker;
use Jackalope\NodeType\NodeTypeManager;
use Jackalope\FactoryInterface;
use Jackalope\NotImplementedException;

use Prismic\Api;
use Prismic\Ref;
use Prismic\Document;

use Guzzle\Http\Exception\RequestException;

/**
 * Class to handle the communication between Jackalope and http://prismic.io.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 */
class Client extends BaseTransport implements QueryTransport
{
    /**
     * The factory to instantiate objects
     * @var FactoryInterface
     */
    protected $factory;

    /**
     * @var bool
     */
    private $loggedIn = false;

    /**
     * @var SimpleCredentials
     */
    private $credentials;

    /**
     * @var string
     */
    protected $workspaceName;

    /**
     * @var NodeTypeManager
     */
    private $nodeTypeManager;

    /**
     * Check if an initial request on login should be send to check if repository exists
     * This is according to the JCR specifications and set to true by default
     * @see setCheckLoginOnServer
     * @var bool
     */
    private $checkLoginOnServer = true;

    /**
     * @var array
     */
    protected $namespaces = array();

    /**
     * @var string uri to the endpoint with a %s placeholder for the workspace name
     */
    private $uri;

    /**
     * @var string prismic access token
     */
    private $accessToken;

    /**
     * @var Api
     */
    private $api;

    /**
     * @var Ref
     */
    private $ref;

    /**
     * @var string
     */
    private $defaultWorkspaceName = 'default';

    /**
     * @var array
     */
    private $bookmarksByName = array();

    /**
     * @var array
     */
    private $bookmarksByUuid = array();


    /**
     * @param FactoryInterface $factory
     * @param string           $uri     Uri to the endpoint with a %s placeholder for the workspace name
     */
    public function __construct(FactoryInterface $factory, $uri)
    {
        $this->factory = $factory;
        $this->uri = $uri;
    }

    /**
     * @return Api
     */
    public function getApi()
    {
        return $this->api;
    }

    /**
     * @return Ref
     */
    public function getRef()
    {
        return $this->ref;
    }

    /**
     * @param Ref $ref
     */
    public function setRef($ref)
    {
        $this->ref = $ref;
    }

    /**
     * @return string
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * @param string $accessToken
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * @param string $defaultWorkspaceName
     */
    public function setDefaultWorkspaceName($defaultWorkspaceName)
    {
        $this->defaultWorkspaceName = $defaultWorkspaceName;
    }

    /**
     * @param string $workspaceName
     * @return string
     */
    private function getApiEndpointUri($workspaceName)
    {
        if ('default' === $workspaceName) {
            $workspaceName = $this->defaultWorkspaceName;
        }

        return sprintf($this->uri, $workspaceName);
    }

    /**
     * {@inheritDoc}
     */
    public function login(CredentialsInterface $credentials = null, $workspaceName = null)
    {
        $this->credentials = $credentials;
        $this->workspaceName = $workspaceName ?: 'default';

        if (!$this->checkLoginOnServer) {
            return $this->workspaceName;
        }

        $apiEndpoint = $this->getApiEndpointUri($this->workspaceName);
        $accessToken = $this->accessToken;
        if ($credentials instanceof SimpleCredentials && null !== $credentials->getUserID()) {
            // TODO oauth login
            $clientId = $credentials->getUserID();
            $clientSecret = $credentials->getPassword();
            //$accessToken = ..
        }

        try {
            // TODO inject a factory to create Prismic\Api instances
            $this->api = Api::get($apiEndpoint, $accessToken);
        } catch (RequestException $e) {
            throw new NoSuchWorkspaceException("Requested workspace: '{$this->workspaceName}'", null, $e);
        } catch (\RuntimeException $e) {
            throw new RepositoryException("Could not connect to endpoint: '$apiEndpoint'", null, $e);
        }

        $this->ref = $this->api->master()->getRef();
        $this->bookmarksByName = (array) $this->api->bookmarks();
        $this->bookmarksByUuid = array_flip($this->bookmarksByName);

        $this->loggedIn = true;

        return $this->workspaceName;
    }

    /**
     * {@inheritDoc}
     */
    public function logout()
    {
        if ($this->loggedIn) {
            $this->loggedIn = false;
            $this->context = null;
        }
    }

    /**
     * Configure whether to check if we are logged in before doing a request.
     *
     * Will improve error reporting at the cost of some round trips.
     */
    public function setCheckLoginOnServer($bool)
    {
        $this->checkLoginOnServer = $bool;
    }

    /**
     * Ensure that we are currently logged in, executing the login in case we
     * did lazy login.
     *
     * @throws RepositoryException if this transport is not logged in.
     */
    protected function assertLoggedIn()
    {
        if (!$this->loggedIn) {
            if (!$this->checkLoginOnServer && $this->workspaceName) {
                $this->checkLoginOnServer = true;
                if ($this->login($this->credentials, $this->workspaceName)) {
                    return;
                }
            }

            throw new RepositoryException('You need to be logged in for this operation');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getRepositoryDescriptors()
    {
        // TODO adjust

        return array(
            RepositoryInterface::IDENTIFIER_STABILITY => RepositoryInterface::IDENTIFIER_STABILITY_INDEFINITE_DURATION,
            RepositoryInterface::REP_NAME_DESC  => 'jackalope_prismic',
            RepositoryInterface::REP_VENDOR_DESC => 'Jackalope Community',
            RepositoryInterface::REP_VENDOR_URL_DESC => 'http://github.com/jackalope',
            RepositoryInterface::REP_VERSION_DESC => '1.1.0-DEV',
            RepositoryInterface::SPEC_NAME_DESC => 'Content Repository for PHP',
            RepositoryInterface::SPEC_VERSION_DESC => '2.1',
            RepositoryInterface::NODE_TYPE_MANAGEMENT_AUTOCREATED_DEFINITIONS_SUPPORTED => false,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_INHERITANCE => RepositoryInterface::NODE_TYPE_MANAGEMENT_INHERITANCE_SINGLE,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_MULTIPLE_BINARY_PROPERTIES_SUPPORTED => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_MULTIVALUED_PROPERTIES_SUPPORTED => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_ORDERABLE_CHILD_NODES_SUPPORTED => false,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_OVERRIDES_SUPPORTED => false,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_PRIMARY_ITEM_NAME_SUPPORTED => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_PROPERTY_TYPES => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_RESIDUAL_DEFINITIONS_SUPPORTED => false,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_SAME_NAME_SIBLINGS_SUPPORTED => false,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_UPDATE_IN_USE_SUPPORTED => false,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_VALUE_CONSTRAINTS_SUPPORTED => true,
            RepositoryInterface::OPTION_ACCESS_CONTROL_SUPPORTED => true,
            RepositoryInterface::OPTION_ACTIVITIES_SUPPORTED => false,
            RepositoryInterface::OPTION_BASELINES_SUPPORTED => false,
            RepositoryInterface::OPTION_JOURNALED_OBSERVATION_SUPPORTED => false,
            RepositoryInterface::OPTION_LIFECYCLE_SUPPORTED => false,
            RepositoryInterface::OPTION_LOCKING_SUPPORTED => false,
            RepositoryInterface::OPTION_NODE_AND_PROPERTY_WITH_SAME_NAME_SUPPORTED => true,
            RepositoryInterface::OPTION_NODE_TYPE_MANAGEMENT_SUPPORTED => false,
            RepositoryInterface::OPTION_OBSERVATION_SUPPORTED => false,
            RepositoryInterface::OPTION_RETENTION_SUPPORTED => false,
            RepositoryInterface::OPTION_SHAREABLE_NODES_SUPPORTED => false,
            RepositoryInterface::OPTION_SIMPLE_VERSIONING_SUPPORTED => true,
            RepositoryInterface::OPTION_TRANSACTIONS_SUPPORTED => false,
            RepositoryInterface::OPTION_UNFILED_CONTENT_SUPPORTED => true,
            RepositoryInterface::OPTION_UPDATE_MIXIN_NODETYPES_SUPPORTED => false,
            RepositoryInterface::OPTION_UPDATE_PRIMARY_NODETYPE_SUPPORTED => false,
            RepositoryInterface::OPTION_VERSIONING_SUPPORTED => false,
            RepositoryInterface::OPTION_WORKSPACE_MANAGEMENT_SUPPORTED => false,
            RepositoryInterface::OPTION_XML_EXPORT_SUPPORTED => true,
            RepositoryInterface::OPTION_XML_IMPORT_SUPPORTED => true,
            RepositoryInterface::QUERY_FULL_TEXT_SEARCH_SUPPORTED => true,
            RepositoryInterface::QUERY_CANCEL_SUPPORTED => false,
            RepositoryInterface::QUERY_JOINS => RepositoryInterface::QUERY_JOINS_NONE,
            RepositoryInterface::QUERY_LANGUAGES => array($this->getSupportedQueryLanguages()),
            RepositoryInterface::QUERY_STORED_QUERIES_SUPPORTED => false,
            RepositoryInterface::WRITE_SUPPORTED => false,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getNamespaces()
    {
        if (empty($this->namespaces)) {
            // TODO get namespaces

            $this->namespaces = array(
                NamespaceRegistryInterface::PREFIX_EMPTY => NamespaceRegistryInterface::NAMESPACE_EMPTY,
                NamespaceRegistryInterface::PREFIX_JCR => NamespaceRegistryInterface::NAMESPACE_JCR,
                NamespaceRegistryInterface::PREFIX_NT => NamespaceRegistryInterface::NAMESPACE_NT,
                NamespaceRegistryInterface::PREFIX_MIX => NamespaceRegistryInterface::NAMESPACE_MIX,
                NamespaceRegistryInterface::PREFIX_XML => NamespaceRegistryInterface::NAMESPACE_XML,
                NamespaceRegistryInterface::PREFIX_SV => NamespaceRegistryInterface::NAMESPACE_SV,
                'prismic' => 'prismic',
            );
        }

        return $this->namespaces;
    }

    /**
     * {@inheritDoc}
     */
    public function getAccessibleWorkspaceNames()
    {
        // TODO get list of workspaces
        return array('default');
    }

    /**
     * @return \stdClass
     */
    private function getRootNode()
    {
        $children = $this->api->forms()->everything->ref($this->ref)->submit();

        $root = new \stdClass();
        $root->{'jcr:primaryType'} = 'nt:unstructured';
        $root->{':jcr:primaryType'} = PropertyType::NAME;
        foreach ($children as $child) {
            $childPath = $this->getNodePathForIdentifier($child->getId());
            $root->{substr($childPath, 1)} = new \stdClass();
        }

        return $root;
    }

    /**
     * {@inheritDoc}
     */
    public function getNode($path)
    {
        $this->assertLoggedIn();
        PathHelper::assertValidAbsolutePath($path);

        try {
            if ('/' === $path) {
                return $this->getRootNode();
            }

            return $this->getNodeByIdentifier($this->getNodeIdentifierForPath($path));
        } catch (ItemNotFoundException $e) {
            throw new ItemNotFoundException("Item $path not found in workspace ".$this->workspaceName);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getNodes($paths)
    {
        $this->assertLoggedIn();

        if (empty($paths)) {
            return array();
        }

        $identifiers = array();
        foreach ($paths as $path) {
            PathHelper::assertValidAbsolutePath($path);
            $identifiers[] = $this->getNodeIdentifierForPath($path);
        }

        return $this->getNodesByIdentifier($identifiers);
    }

    /**
     * @param Document $doc
     * @return \stdClass
     */
    private function getNodeData(Document $doc)
    {
        $node = new \stdClass();
        $node->{'jcr:primaryType'} = 'prismic:'.$doc->getType();
        $node->{':jcr:primaryType'} = PropertyType::STRING;
        $node->{'jcr:mixinTypes'} = array ('mix:referenceable');
        $node->{':jcr:mixinTypes'} = PropertyType::STRING;
        $node->{'jcr:uuid'} = $doc->getId();
        $node->{':jcr:uuid'} = PropertyType::NAME;

        $node->slug = $doc->getSlug();
        $node->{':slug'} = PropertyType::STRING;
        $node->slugs = (array) $doc->getSlugs();
        $node->{':slugs'} = PropertyType::STRING;
        $node->tags = (array) $doc->getTags();
        $node->{':tags'} = PropertyType::STRING;
        foreach ($doc->getFragments() as $name => $fragment) {
            // TODO model StructuredText/ImageView/Embed/Group etc. as children
            switch (get_class($fragment)) {
                case 'Prismic\Fragment\Date':
                    $node->{$name} = $fragment->asText();
                    $node->{":$name"} = PropertyType::DATE;
                    break;
                case 'Prismic\Fragment\Number':
                    $node->{$name} = $fragment->asText();
                    $node->{":$name"} = PropertyType::LONG;
                    break;
                case 'Prismic\Fragment\Image':
                    $node->{$name} = $fragment->asText();
                    $node->{":$name"} = PropertyType::BINARY;
                    break;
                case 'Prismic\Fragment\ImageView':
                    $node->{":$name"} = PropertyType::URI;
                    $node->{$name} = $fragment->getUrl();
                    break;
                default:
                    $node->{$name} = $fragment->asText();
                    $node->{":$name"} = PropertyType::STRING;
            }
        }

        return $node;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodeByIdentifier($uuid)
    {
        $this->assertLoggedIn();

        $docs = $this->api->forms()->everything->ref($this->ref)->query(
                '[[:d = at(document.id, "'.$uuid.'")]]'
            )
            ->submit()
        ;

        if (!is_array($docs) || empty($docs[0])) {
            throw new ItemNotFoundException("Item $uuid not found in workspace ".$this->workspaceName);
        }

        return $this->getNodeData($docs[0]);
    }

    /**
     * {@inheritDoc}
     */
    public function getNodesByIdentifier($identifiers)
    {
        $this->assertLoggedIn();
        if (empty($identifiers)) {
            return array();
        }

        $docs = $this->api->forms()->everything->ref($this->ref)->query(
                '[[:d = any(document.id, ["'.implode('", "', $identifiers).'"])]]'
            )
            ->submit()
        ;

        $nodes = array();
        foreach ($docs as $doc) {
            $nodes[$this->getNodePathForIdentifier($doc->getId())] = $this->getNodeData($doc);
        }

        return $nodes;
    }

    /**
     * @param $path
     * @param null $workspace
     * @return string
     * @throws \Jackalope\NotImplementedException
     */
    private function getNodeIdentifierForPath($path, $workspace = null)
    {
        if (null !== $workspace) {
            throw new NotImplementedException('Specifying the workspace is not yet supported.');
        }

        $this->assertLoggedIn();

        $relPath = substr($path, 1);
        return isset($this->bookmarksByName->{$relPath}) ? $this->bookmarksByName->{$relPath}->getId() : $relPath;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodePathForIdentifier($uuid, $workspace = null)
    {
        if (null !== $workspace) {
            throw new NotImplementedException('Specifying the workspace is not yet supported.');
        }

        $this->assertLoggedIn();

        // TODO do we need to verify the existence?
        $path = isset($this->bookmarksByUuid[$uuid]) ? $this->bookmarksByUuid[$uuid] : '/'.$uuid;
        if (!$path) {
            throw new ItemNotFoundException("no item found with uuid ".$uuid);
        }

        return $path;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodeTypes($nodeTypes = array())
    {
        $standardTypes = StandardNodeTypes::getNodeTypeData();

        $userTypes = $this->fetchUserNodeTypes();

        if ($nodeTypes) {
            $nodeTypes = array_flip($nodeTypes);
            return array_values(array_intersect_key($standardTypes, $nodeTypes) + array_intersect_key($userTypes, $nodeTypes));
        }

        return array_values($standardTypes + $userTypes);
    }

    /**
     * Fetch a user-defined node-type definition.
     *
     * @param string $name
     *
     * @return array
     */
    protected function fetchUserNodeTypes()
    {
        $types = $this->api->getData()->getTypes();
        $result = array();
        foreach ($types as $type) {
            $result["prismic:$type"] = array(
                'name' => "prismic:$type",
                'isAbstract' => false,
                'isMixin' => false,
                'isQueryable' => true,
                'hasOrderableChildNodes' => true,
                'primaryItemName' => null,
                'declaredSuperTypeNames' => array(
                    0 => 'nt:unstructured',
                ),
                'declaredPropertyDefinitions' => array(),
                'declaredNodeDefinitions' => array(
                    array(
                        'declaringNodeType' => "prismic:$type",
                        'name' => 'jcr:system',
                        'isAutoCreated' => false,
                        'isMandatory' => false,
                        'isProtected' => false,
                        'onParentVersion' => 5,
                        'allowsSameNameSiblings' => false,
                        'defaultPrimaryTypeName' => "prismic:$type",
                        'requiredPrimaryTypeNames' =>
                            array("prismic:$type"),
                    ),
                ),
            );
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function setNodeTypeManager($nodeTypeManager)
    {
        $this->nodeTypeManager = $nodeTypeManager;
    }

    /**
     * {@inheritDoc}
     */
    public function getBinaryStream($path)
    {
        $this->assertLoggedIn();

        $nodePath = PathHelper::getParentPath($path);
        $propertyName = PathHelper::getNodeName($path);

        $streams = array();

        // TODO implement
        $data = array();
        foreach ($data as $row) {
            if (is_resource($row['data'])) {
                $stream = $row['data'];
            } else {
                $stream = fopen('php://memory', 'rwb+');
                fwrite($stream, $row['data']);
                rewind($stream);
            }

            $streams[] = $stream;
        }

        // TODO even a multi value field could have only one value stored
        // we need to also fetch if the property is multi valued instead of this count() check
        if (count($data) > 1) {
            return $streams;
        }

        return reset($streams);
    }

    /**
     * {@inheritDoc}
     */
    public function getProperty($path)
    {
        throw new NotImplementedException('Getting properties by path is implemented yet');
    }

    /**
     * {@inheritDoc}
     */
    public function query(Query $query)
    {
        $this->assertLoggedIn();

        if (!$query instanceof QueryObjectModelInterface) {
            $parser = new Sql2ToQomQueryConverter($this->factory->get('Query\QOM\QueryObjectModelFactory'));
            try {
                $qom = $parser->parse($query->getStatement());
                $qom->setLimit($query->getLimit());
                $qom->setOffset($query->getOffset());
            } catch (\Exception $e) {
                throw new InvalidQueryException('Invalid query: '.$query->getStatement(), null, $e);
            }
        } else {
            $qom = $query;
        }

        $qomWalker = new QOMWalker($this->nodeTypeManager, $this->api, $this->getNamespaces());
        list($selectors, $selectorAliases, $query) = $qomWalker->walkQOMQuery($qom);

        $primarySource = reset($selectors);
        $primaryType = $primarySource->getSelectorName() ?: $primarySource->getNodeTypeName();
        $data = $this->api->forms()->everything->ref($this->ref)->query($query)->submit();

        // TODO implement
        $results = array();

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function getSupportedQueryLanguages()
    {
        return array(
            QueryInterface::JCR_SQL2,
            QueryInterface::JCR_JQOM,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getReferences($path, $name = null)
    {
        return $this->getNodeReferences($path, $name, false);
    }

    /**
     * {@inheritDoc}
     */
    public function getWeakReferences($path, $name = null)
    {
        return $this->getNodeReferences($path, $name, true);
    }

    /**
     * @param string  $path           the path for which we need the references
     * @param string  $name           the name of the referencing properties or null for all
     * @param boolean $weakReference  whether to get weak or strong references
     *
     * @return array list of paths to nodes that reference $path
     */
    private function getNodeReferences($path, $name = null, $weakReference = false)
    {
        // TODO implement

        return array();
    }
}
