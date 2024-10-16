<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2021 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Job;

use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Config;

use Espo\ORM\EntityManager;

use Espo\Entities\Job as JobEntity;

use DateTimeImmutable;

abstract class AbstractGroupJob implements JobPreparable
{
    private const PORTION_NUMBER = 100;

    private $jobManager;

    private $entityManager;

    private $config;

    public function __construct(
        JobManager $jobManager,
        EntityManager $entityManager,
        Config $config
    ) {
        $this->jobManager = $jobManager;
        $this->entityManager = $entityManager;
        $this->config = $config;
    }

    public function run(JobData $data): void
    {
        $limit = $this->config->get('jobGroupMaxPortion') ?? self::PORTION_NUMBER;

        $group = $data->get('group');

        $this->jobManager->processGroup($group, $limit);
    }

    public function prepare(ScheduledJobData $data, DateTimeImmutable $executeTime): void
    {
        $groupList = [];

        $query = $this->entityManager
            ->getQueryBuilder()
            ->select('group')
            ->from(JobEntity::ENTITY_TYPE)
            ->where([
                'status' => JobStatus::PENDING,
                'queue' => null,
                'group!=' => null,
                'executeTime<=' => $executeTime->format(DateTime::SYSTEM_DATE_TIME_FORMAT),
            ])
            ->group('group')
            ->build();

        $sth = $this->entityManager->getQueryExecutor()->execute($query);

        while ($row = $sth->fetch()) {
            $group = $row['group'];

            if ($group === null) {
                continue;
            }

            $groupList[] = $group;
        }

        if (!count($groupList)) {
            return;
        }

        foreach ($groupList as $group) {
            $existingJob = $this->entityManager
                ->getRDBRepository(JobEntity::ENTITY_TYPE)
                ->select('id')
                ->where([
                    'scheduledJobId' => $data->getId(),
                    'targetGroup' => $group,
                    'status' => [
                        JobStatus::RUNNING,
                        JobStatus::READY,
                        JobStatus::PENDING,
                    ],
                ])
                ->findOne();

            if ($existingJob) {
                continue;
            }

            $name = $data->getName() . ' :: ' . $group;

            $this->entityManager->createEntity(JobEntity::ENTITY_TYPE, [
                'scheduledJobId' => $data->getId(),
                'executeTime' => $executeTime->format(DateTime::SYSTEM_DATE_TIME_FORMAT),
                'name' => $name,
                'data' => [
                    'group' => $group,
                ],
                'targetGroup' => $group,
            ]);
        }
    }
}
