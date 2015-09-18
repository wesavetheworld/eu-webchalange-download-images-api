<?php
namespace Application\V1\Rest\Images;

use Application\V1\Entity\PageInterface;
use Application\V1\Entity\Pages as PagesEntity;
use Doctrine\ORM\EntityManagerInterface;
use Rhumsaa\Uuid\Uuid;
use ZF\ApiProblem\ApiProblem;
use ZF\Rest\AbstractResourceListener;

class ImagesResource extends AbstractResourceListener
{
    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Fetch all or a subset of resources
     *
     * @param  array $params
     * @return ApiProblem|mixed
     */
    public function fetchAll($params = array())
    {
        $pageId = $this->getEvent()->getRouteMatch()->getParam('page_id', null);

        if (!Uuid::isValid($pageId)) {
            return new ApiProblem(400, 'Invalid identifier');
        }

        /* @var $pageEntity PagesEntity */
        $pageEntity = $this->entityManager
                           ->getRepository('Application\V1\Entity\Pages')
                           ->findOneByUuid($pageId);

        if (is_null($pageEntity)) {
            return new ApiProblem(404, 'Not found result of missed page');
        }

        if ($pageEntity->getStatus() !== PageInterface::STATUS_DONE) {
            return new ApiProblem(428, 'Not all images downloaded yet');
        }

        return $this->entityManager
                    ->getRepository('Application\V1\Entity\Images')
                    ->findByPage($pageEntity);
    }
}