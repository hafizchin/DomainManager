<?php

namespace DomainManager\Entity;

use Omeka\Entity\AbstractEntity;

/**
 * DomainSiteMapping
 *
 * @Table(name="domain_site_mapping")
 * @Entity
 */
class DomainSiteMapping extends AbstractEntity
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * @Column(type="integer", unique=true)
     */
    protected $site_id;

    /**
     * @Column(type="string", length=253, unique=true)
     */
    protected $domain;

    public function getId()
    {
        return $this->id;
    }

    public function getSiteId()
    {
        return $this->site_id;
    }

    public function getDomain()
    {
        return $this->domain;
    }

    public function setSiteId($site_id)
    {
        $this->site_id = $site_id;
    }

    public function setDomain($domain)
    {
        $this->domain = $domain;
    }
}
