<?php
namespace Application\QueueJob;

use Application\V1\Entity\PageInterface;
use Doctrine\ORM\EntityManagerInterface;
use SlmQueue\Job\AbstractJob;
use SlmQueue\Queue\QueueAwareInterface;
use SlmQueue\Queue\QueueAwareTrait;
use SlmQueue\Queue\QueueInterface;
use Zend\Dom\Document;
use Zend\Http\Client as HttpClient;

class ParsePage extends AbstractJob implements QueueAwareInterface
{
    use QueueAwareTrait;

    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var Document\Query
     */
    protected $documentQuery;

    /**
     * @var QueueInterface
     */
    protected $grabImageQueue;

    /**
     * @param HttpClient $httpClient
     * @param EntityManagerInterface $entityManager
     * @param Document\Query $documentQuery
     * @param QueueInterface $grabImageQueue
     */
    public function __construct(HttpClient $httpClient,
                                EntityManagerInterface $entityManager,
                                Document\Query $documentQuery,
                                QueueInterface $grabImageQueue)
    {
        $this->httpClient = $httpClient;
        $this->entityManager = $entityManager;
        $this->documentQuery = $documentQuery;
        $this->grabImageQueue = $grabImageQueue;
    }

    /**
     * @param string $url
     * @param string $scheme
     * @param string $host
     *
     * @return string
     */
    protected function normalizeSchemeAndHost($url, $scheme, $host)
    {
        if (($_url = parse_url($url)) !== false){ // valid url
            if (empty($_url['scheme'])) {
                $newUrl = strtolower($scheme). "://";
            } else {
                $newUrl = strtolower($_url['scheme']) . "://";
            }

            if (!empty($_url['user']) && !empty($_url['pass'])) {
                $newUrl .= $_url['user'] . ":" . $_url['pass'] . "@";
            }

            if (empty($_url['host'])) {
                $newUrl .= strtolower($host);
            } else {
                $newUrl .= strtolower($_url['host']);
            }

            $newUrl .= $_url['path'];
            if (!empty($_url['query'])) {
                $newUrl .= "?" . $_url['query'];
            }

            if (!empty($_url['fragment'])) {
                $newUrl .= "#" . $_url['fragment'];
            }
            return $newUrl;
        }
        return $url; // could return false if you'd like
    }

    public function execute()
    {
        try {
            $payload = $this->getContent();
            echo "processing >> " . $payload['page_url'] .
                 " >> for id >> " . $payload['page_id'] . "\n";

            $this->httpClient->setUri( $payload['page_url'] );

            $response = $this->httpClient->send();

            $document = new Document( $response->getBody() );
            $manager = $this->grabImageQueue
                            ->getJobPluginManager();

            $jobs = [];
            $parsedPageUrl = parse_url($payload['page_url']);
            $cnt = 0;
            /* @var \DOMElement $node */
            foreach ( $this->documentQuery->execute( '//body//img', $document ) as $node ) {
                $job = $manager->get( 'Application\QueueJob\GrabImage' );
                $src = $this->normalizeSchemeAndHost($node->getAttribute( 'src' ),
                                                     $parsedPageUrl['scheme'],
                                                     $parsedPageUrl['host']);

                $ext = strtolower( pathinfo( $src, PATHINFO_EXTENSION ) );
                if (in_array($ext, array( 'gif', 'bmp', 'jpg', 'jpeg', 'png', 'apng', 'svg', 'ico' ))) {
                    $job->setContent( [ 'image_src' => $src,
                                        'image_ext' => $ext,
                                        'page_id' => $payload['page_id'] ] );
                    $jobs[]=$job;
                    $cnt++;
                }
            }

            /* @var \Application\V1\Entity\Pages $pageEntity */
            $pageEntity = $this->entityManager
                               ->find('Application\V1\Entity\Pages',
                                   $payload['page_id']);

            if ($cnt < 1) {
                $pageEntity->setStatus(PageInterface::STATUS_DONE);
            }

            $pageEntity->setPendingImagesCnt($cnt);
            $pageEntity->setTotalImagesCnt($cnt);

            $this->entityManager->flush();

            array_map([$this->grabImageQueue, 'push'], $jobs);

        } catch (\Exception $e) {
            \Application\Stdlib\Debug\Utility::dump( $e->getMessage() );
            exit(1);
        }
    }
}