<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\ElasticsearchBundle\Tests\Functional\Command;

use ONGR\ElasticsearchBundle\Command\IndexCreateCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class CreateIndexCommandTest extends AbstractCommandTestCase
{
    /**
     * Execution data provider.
     *
     * @return array
     */
    public function getTestExecuteData()
    {
        return [
            [
                'foo',
                [
                    '--no-mapping' => null,
                ],
                [],
            ],
            [
                'default',
                [],
                [],
            ],
        ];
    }

    /**
     * Tests creating index in case of existing this index. Configuration from tests yaml.
     */
    public function testExecuteWhenIndexExists()
    {
        $manager = $this->getManager();

        if (!$manager->indexExists()) {
            $manager->createIndex();
        }

        // Initialize command
        $commandName = 'ongr:es:index:create';
        $commandTester = $this->getCommandTester($commandName);
        $options = [];
        $arguments['command'] = $commandName;
        $arguments['--manager'] = $manager->getName();
        $arguments['--if-not-exists'] = null;

        // Test if the command returns 0 or not
        $this->assertSame(
            0,
            $commandTester->execute($arguments, $options)
        );

        $expectedOutput = sprintf(
            '/Index `%s` already exists in `%s` manager./',
            $manager->getIndexName(),
            $manager->getName()
        );

        // Test if the command output matches the expected output or not
        $this->assertRegexp($expectedOutput, $commandTester->getDisplay());
    }

    /**
     * Tests creating index. Configuration from tests yaml.
     *
     * @param string $managerName
     * @param array  $arguments
     * @param array  $options
     *
     * @dataProvider getTestExecuteData
     */
    public function testExecute($managerName, $arguments, $options)
    {
        $manager = $this->getManager($managerName);

        if ($manager->indexExists()) {
            $manager->dropIndex();
        }

        $this->runIndexCreateCommand($managerName, $arguments, $options);

        $this->assertTrue($manager->indexExists(), 'Index should exist.');
        $manager->dropIndex();
    }

    /**
     * Testing if creating index with alias option will switch alias correctly to the new index.
     */
    public function testAliasIsCreatedCorrectly()
    {
        $manager = $this->getManager();

        $aliasName = $manager->getIndexName();
        $finder = $this->getContainer()->get('es.client.index_suffix_finder');
        $finder->setNextFreeIndex($manager);
        $oldIndexName = $manager->getIndexName();
        $manager->createIndex();

        $this->assertTrue($manager->indexExists());
        $this->assertFalse($manager->getClient()->indices()->existsAlias(['name' => $aliasName]));

        $this->runIndexCreateCommand($manager->getName(), ['--time' => null, '--alias' => null], []);

        $aliases = $manager->getClient()->indices()->getAlias(['name' => $aliasName]);
        $newIndexNames = array_keys($aliases);

        $this->assertCount(1, $newIndexNames);
        $this->assertTrue($manager->getClient()->indices()->existsAlias(['name' => $aliasName]));
        $this->assertNotEquals($manager->getIndexName(), $newIndexNames);

        $manager->setIndexName($newIndexNames[0]);
        $manager->dropIndex();
        $manager->setIndexName($oldIndexName);
        $manager->dropIndex();
    }

    /**
     * Testing if aliases are correctly changed from one index to the next after multiple command calls
     */
    public function testAliasIsChangedCorrectly()
    {
        $manager = $this->getManager();
        $aliasName = $manager->getIndexName();

        $this->runIndexCreateCommand($manager->getName(), ['--time' => null, '--alias' => null], []);
        $this->assertTrue($manager->getClient()->indices()->existsAlias(['name' => $aliasName]));
        $aliases = $manager->getClient()->indices()->getAlias(['name' => $aliasName]);
        $this->assertCount(1, array_keys($aliases));
        $aliasedIndex1 = array_keys($aliases)[0];

        $this->assertNotEquals($manager->getIndexName(), $aliasedIndex1);
        $this->runIndexCreateCommand($manager->getName(), ['--time' => null, '--alias' => null], []);

        $aliases = $manager->getClient()->indices()->getAlias(['name' => $aliasName]);
        $this->assertCount(1, array_keys($aliases));
        $aliasedIndex2 = array_keys($aliases)[0];
        $this->assertNotEquals($aliasedIndex1, $aliasedIndex2);

        $manager->setIndexName($aliasedIndex1);
        $manager->dropIndex();
        $manager->setIndexName($aliasedIndex2);
        $manager->dropIndex();
    }

    /**
     * Tests if the json containing index mapping is returned when --dump option is provided
     */
    public function testIndexMappingDump()
    {
        $manager = $this->getManager();

        $commandName = 'ongr:es:index:create';
        $commandTester = $this->getCommandTester($commandName);
        $options = [];
        $arguments['command'] = $commandName;
        $arguments['--dump'] = null;

        // Test if the command returns 0 or not
        $this->assertSame(
            0,
            $commandTester->execute($arguments, $options)
        );

        // Test if the command output contains the expected output or not
        $this->assertContains(
            json_encode(
                $manager->getIndexMappings(),
                JSON_PRETTY_PRINT
            ),
            $commandTester->getDisplay()
        );
    }

    /**
     * Runs the index create command.
     *
     * @param string $managerName
     * @param array  $arguments
     * @param array  $options
     */
    protected function runIndexCreateCommand($managerName, array $arguments = [], array $options = [])
    {
        // Creates index.
        $commandName = 'ongr:es:index:create';
        $commandTester = $this->getCommandTester($commandName);
        $arguments['command'] = $commandName;
        $arguments['--manager'] = $managerName;

        $commandTester->execute($arguments, $options);
    }

    /**
     * Returns create index command with assigned container.
     *
     * @return IndexCreateCommand
     */
    protected function getCreateCommand()
    {
        $command = new IndexCreateCommand();
        $command->setContainer($this->getContainer());

        return $command;
    }

    /**
     * Returns command tester.
     * @param string commandName
     *
     * @return CommandTester
     */
    protected function getCommandTester($commandName)
    {
        $app = new Application();
        $app->add($this->getCreateCommand());

        $command = $app->find($commandName);
        $commandTester = new CommandTester($command);

        return $commandTester;
    }
}
