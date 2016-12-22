<?php

namespace SilverStripe\AssetAdmin\Tests\Controller;

use SilverStripe\AssetAdmin\Tests\Controller\AssetAdminTest\FileExtension;
use SilverStripe\AssetAdmin\Tests\Controller\AssetAdminTest\FolderExtension;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Tests\Storage\AssetStoreTest\TestAssetStore;
use SilverStripe\Control\Director;
use SilverStripe\Control\Session;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\Versioning\Versioned;
use SilverStripe\Security\SecurityToken;

/**
 * Tests {@see AssetAdmin}
 */
class AssetAdminTest extends FunctionalTest
{

    protected static $fixture_file = 'AssetAdminTest.yml';

    /**
     * @var Session
     */
    protected $session = null;

    public function setUp()
    {
        parent::setUp();

        TestAssetStore::activate('AssetAdminTest');
        $memberID = $this->logInWithPermission('ADMIN');
        $this->session = Session::create(array('loggedInAs' => $memberID));

        File::add_extension(FileExtension::class);
        Folder::add_extension(FolderExtension::class);

        // Create a test folders for each of the fixture references
        foreach (File::get()->filter('ClassName', Folder::class) as $folder) {
            /** @var Folder $folder */
            $folder->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        }

        // Create a test files for each of the fixture references
        $content = str_repeat('x', 1000000);
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            /** @var File $file */
            $file->setFromString($content, $file->generateFilename());
            $file->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        }

        // Override FunctionalTest defaults
        SecurityToken::enable();
        $this->session->inst_set('SecurityID', SecurityToken::inst()->getValue());
    }

    public function tearDown()
    {
        File::remove_extension(FileExtension::class);
        Folder::remove_extension(FolderExtension::class);

        TestAssetStore::reset();
        parent::tearDown();
    }


    public function testApiHistory()
    {
        $file = $this->objFromFixture(File::class, 'file1');
        $response = Director::test(
            'admin/assets/api/history?fileId='. $file->ID,
            null,
            $this->session,
            'GET'
        );

        $this->assertFalse($response->isError());

        $body = json_decode($response->getBody(), true);

        $this->assertArrayHasKey('summary', $body[0]);
        $this->assertArrayHasKey('versionid', $body[0]);
        $this->assertArrayHasKey('summary', $body[0]);

        // test permission filtering and
    }


    public function testItCreatesFolder()
    {
        $folder1 = $this->objFromFixture(Folder::class, 'folder1');

        $response = Director::test(
            'admin/assets/api/createFolder',
            [
                'ParentID' => $folder1->ID,
                'Name' => 'testItCreatesFolder',
                'SecurityID' => SecurityToken::inst()->getValue(),
            ],
            $this->session,
            'POST'
        );
        $this->assertFalse($response->isError());
        $responseData = json_decode($response->getBody(), true);
        $newFolder = Folder::get()->byID($responseData['id']);
        $this->assertNotNull($newFolder);
        $this->assertEquals($folder1->ID, $newFolder->ParentID);
        $this->assertEquals('testItCreatesFolder', $newFolder->Name);
    }

    public function testItRestrictsCreateFolderByCanCreate()
    {
        $folder = $this->objFromFixture(Folder::class, 'folder1');

        $response = Director::test(
            'admin/assets/api/createFolder',
            [
                'ParentID' => $folder->ID,
                'Name' => 'disallowCanCreate',
                'SecurityID' => SecurityToken::inst()->getValue(),
            ],
            $this->session,
            'POST'
        );
        $this->assertTrue($response->isError());
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testItRestrictsCreateFolderByCanAddChildren()
    {
        $folder = $this->objFromFixture(Folder::class, 'disallowCanAddChildren');

        $response = Director::test(
            'admin/assets/api/createFolder',
            [
                'ParentID' => $folder->ID,
                'Name' => 'testItRestrictsCreateFolderByCanAddChildren',
                'SecurityID' => SecurityToken::inst()->getValue(),
            ],
            $this->session,
            'POST'
        );
        $this->assertTrue($response->isError());
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testItCreatesFile()
    {
        $folder1 = $this->objFromFixture(Folder::class, 'folder1');

        /** @skipUpgrade */
        $fileData = array('Upload' => $this->getUploadFile('Upload', 'testItCreatesFile.txt'));
        $_FILES = $fileData;
        $postedData = array_merge(
            $fileData,
            [
                    'ParentID' => $folder1->ID,
                    'SecurityID' => SecurityToken::inst()->getValue(),
                ]
        );
        $response = Director::test(
            'admin/assets/api/createFile',
            $postedData,
            $this->session,
            'POST'
        );
        $this->assertFalse($response->isError());
        $responseData = json_decode($response->getBody(), true);
        $newFile = File::get()->byID($responseData[0]['id']);
        $this->assertNotNull($newFile);
        $this->assertEquals($folder1->ID, $newFile->ParentID);
        $this->assertEquals('testItCreatesFile.txt', $newFile->Name);

        // Test that duplicate uploads are renamed
        $response = Director::test(
            'admin/assets/api/createFile',
            $postedData,
            $this->session,
            'POST'
        );
        $this->assertFalse($response->isError());
        $responseData = json_decode($response->getBody(), true);
        $newFile2 = File::get()->byID($responseData[0]['id']);
        $this->assertNotNull($newFile2);
        $this->assertEquals($folder1->ID, $newFile2->ParentID);
        $this->assertNotEquals($newFile->ID, $newFile2->ID);
        $this->assertEquals('testItCreatesFile-v2.txt', $newFile2->Name);
    }

    public function testItRestrictsCreateFileOnCanCreate()
    {
        $folder = $this->objFromFixture(Folder::class, 'folder1');

        $fileData = array('Upload' => $this->getUploadFile('Upload', 'disallowCanCreate.txt'));
        $_FILES = $fileData;
        $response = Director::test(
            'admin/assets/api/createFile',
            array_merge(
                $fileData,
                [
                    'ParentID' => $folder->ID,
                    'SecurityID' => SecurityToken::inst()->getValue(),
                ]
            ),
            $this->session,
            'POST'
        );
        $this->assertTrue($response->isError());
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testItRestrictsCreateFileOnCanAddChildren()
    {
        $folder = $this->objFromFixture(Folder::class, 'disallowCanAddChildren');

        /** @skipUpgrade */
        $fileData = array('Upload' => $this->getUploadFile('Upload', 'test.txt'));
        $_FILES = $fileData;
        $response = Director::test(
            'admin/assets/api/createFile',
            array_merge(
                $fileData,
                [
                    'ParentID' => $folder->ID,
                    'SecurityID' => SecurityToken::inst()->getValue(),
                ]
            ),
            $this->session,
            'POST'
        );
        $this->assertTrue($response->isError());
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testItRestrictsCreateFileOnExtension()
    {
        $folder1 = $this->objFromFixture(
            Folder::class,
            'folder1'
        );

        /** @skipUpgrade */
        $fileData = array('Upload' => $this->getUploadFile('Upload', 'disallowed.php'));
        $_FILES = $fileData;
        $response = Director::test(
            'admin/assets/api/createFile',
            array_merge(
                $fileData,
                [
                    'ParentID' => $folder1->ID,
                    'SecurityID' => SecurityToken::inst()->getValue(),
                ]
            ),
            $this->session,
            'POST'
        );
        $this->assertTrue($response->isError());
        $this->assertEquals(400, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertContains(
            'Extension is not allowed',
            $responseData['message']['value']
        );
    }

    public function testItFiltersByDateInSearch()
    {
        $file1 = $this->objFromFixture(File::class, 'file1');
        $file2 = $this->objFromFixture(File::class, 'file2');

        // Force creation times
        $file1->Created = '2014-01-05 23:11:39';
        $file1->write();
        $file2->Created = '2014-01-06 12:00:00';
        $file2->write();

        // Mock searches for 4th Jan
        $results = $this->getResultsForSearch([
            'search' => [
                'CreatedFrom' => '2014-01-04',
                'CreatedTo' => '2014-01-04',
                'AllFolders' => '1'
            ]
        ]);
        $this->assertEquals(0, count($results['files']));

        // Mock searches for 5th Jan
        $results = $this->getResultsForSearch([
            'search' => [
                'CreatedFrom' => '2014-01-05',
                'CreatedTo' => '2014-01-05',
                'AllFolders' => '1'
            ]
        ]);
        $this->assertEquals(1, count($results['files']));
        $this->assertContains($file1->ID, array_column($results['files'], 'id'));


        // Mock searches for 5th-6th Jan
        $results = $this->getResultsForSearch([
            'search' => [
                'CreatedFrom' => '2014-01-05',
                'CreatedTo' => '2014-01-06',
                'AllFolders' => '1'
            ]
        ]);
        $this->assertEquals(2, count($results['files']));
        $this->assertContains($file1->ID, array_column($results['files'], 'id'));
        $this->assertContains($file2->ID, array_column($results['files'], 'id'));

        // Mock searches for 6th Jan
        $results = $this->getResultsForSearch([
            'search' => [
                'CreatedFrom' => '2014-01-06',
                'CreatedTo' => '2014-01-06',
                'AllFolders' => '1'
            ]
        ]);
        $this->assertEquals(1, count($results['files']));
        $this->assertContains($file2->ID, array_column($results['files'], 'id'));

        // Mock searches for 7th Jan
        $results = $this->getResultsForSearch([
            'search' => [
                'CreatedFrom' => '2014-01-07',
                'CreatedTo' => '2014-01-07',
                'AllFolders' => '1'
            ]
        ]);
        $this->assertEquals(0, count($results['files']));
    }

    public function testItDoesNotFilterWithAllFoldersFlagInSearch()
    {
        $rootfile = $this->objFromFixture(File::class, 'rootfile');
        $file1 = $this->objFromFixture(File::class, 'file1');
        $folder1 = $this->objFromFixture(Folder::class, 'folder1');

        $results = $this->getResultsForSearch([
            'search' => [
                'AllFolders' => '1'
            ]
        ]);
        $this->assertContains(
            $rootfile->ID,
            array_column($results['files'], 'id'),
            'Contains top level file'
        );
        $this->assertContains(
            $folder1->ID,
            array_column($results['files'], 'id'),
            'Contains top level folder'
        );
        $this->assertContains(
            $file1->ID,
            array_column($results['files'], 'id'),
            'Contains files in subfolder'
        );
    }

    public function testItDoesFilterWithFolderByDefaultInSearch()
    {
        $rootfile = $this->objFromFixture(File::class, 'rootfile');
        $file1 = $this->objFromFixture(File::class, 'file1');
        $folder1 = $this->objFromFixture(Folder::class, 'folder1');

        $results = $this->getResultsForSearch([
            'id' => $folder1->ID
        ]);
        $this->assertNotContains(
            $rootfile->ID,
            array_column($results['files'], 'id'),
            'Not contain top level file'
        );
        $this->assertContains(
            $file1->ID,
            array_column($results['files'], 'id'),
            'Contains files in subfolder'
        );
    }

    public function testItFiltersByParentInSearch()
    {
        $file1 = $this->objFromFixture(File::class, 'file1');
        $file2 = $this->objFromFixture(File::class, 'file2');
        $file1Folder = $file1->Parent();
        $file2Folder = $file2->Parent();

        $results = $this->getResultsForSearch([
            'id' => $file1Folder->ID,
            'search' => [
                'Name' => $file1->Name
            ]
        ]);
        $this->assertEquals(1, count($results['files']));
        $this->assertContains(
            $file1->ID,
            array_column($results['files'], 'id'),
            'Returns file when contained in correct folder'
        );

        $results = $this->getResultsForSearch([
            'id' => $file2Folder->ID,
            'search' => [
                'Name' => $file1->Name
            ]
        ]);
        $this->assertEquals(
            0,
            count($results['files']),
            'Does not return file when contained in different folder'
        );
    }

    public function testItFiltersByNameInSearch()
    {
        $file1 = $this->objFromFixture(File::class, 'file1');

        $results = $this->getResultsForSearch([
            'search' => [
                'Name' => $file1->Name,
                'AllFolders' => '1'
            ]
        ]);
        $this->assertEquals(
            1,
            count($results['files']),
            'Finds by Name property'
        );
        $this->assertContains($file1->ID, array_column($results['files'], 'id'));

        $results = $this->getResultsForSearch([
            'search' => [
                'Name' => $file1->Title,
                'AllFolders' => '1'
            ]
        ]);
        $this->assertEquals(
            1,
            count($results['files']),
            'Finds by Title property'
        );
        $this->assertContains($file1->ID, array_column($results['files'], 'id'));
    }

    public function testItRestrictsViewInSearch()
    {
        $allowedFile = $this->objFromFixture(File::class, 'file1');
        $disallowedFile = $this->objFromFixture(File::class, 'disallowCanView');

        $results = $this->getResultsForSearch([
            'search' => [
                'Name' => $allowedFile->Name,
                'AllFolders' => '1'
            ]
        ]);
        $this->assertEquals(1, count($results['files']));
        $this->assertContains($allowedFile->ID, array_column($results['files'], 'id'));

        $results = $this->getResultsForSearch([
            'search' => [
                'Name' => $disallowedFile->Name,
                'AllFolders' => '1'
            ]
        ]);
        $this->assertEquals(0, count($results['files']));
    }

    public function testItRestrictsViewInReadFolder()
    {
        $folder1 = $this->objFromFixture(Folder::class, 'folder1');
        $allowedFile = $this->objFromFixture(File::class, 'file1');
        $disallowedFile = $this->objFromFixture(File::class, 'disallowCanView');

        $response = $this->get('admin/assets/api/readFolder?' . http_build_query(['id' => $folder1->ID]));
        $files = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('files', $files);
        $ids = array_map(function ($file) {
            return $file['id'];
        }, $files['files']);
        $this->assertContains($allowedFile->ID, $ids);
        $this->assertEquals($allowedFile->ParentID, $folder1->ID);
        $this->assertNotContains($disallowedFile->ID, $ids);
        $this->assertEquals($disallowedFile->ParentID, $folder1->ID);
    }

    public function testItRestrictsUpdateFile()
    {
        $allowedFile = $this->objFromFixture(File::class, 'file1');
        $disallowedFile = $this->objFromFixture(File::class, 'disallowCanEdit');

        $response = Director::test(
            'admin/assets/FileEditForm',
            [
                'action_save' => 1,
                'ID' => $allowedFile->ID,
                'Name' => 'disallowCanEdit.txt',
                'Title' => 'new',
                'SecurityID' => SecurityToken::inst()->getValue(),
            ],
            $this->session
        );
        $this->assertFalse($response->isError());

        $response = Director::test(
            'admin/assets/FileEditForm',
            [
                'action_save' => 1,
                'ID' => $disallowedFile->ID,
                'Title' => 'new',
                'SecurityID' => SecurityToken::inst()->getValue(),
            ],
            $this->session
        );
        $this->assertTrue($response->isError());
    }

    public function testItRestrictsDelete()
    {
        $allowedFile = $this->objFromFixture(File::class, 'file1');
        $disallowedFile = $this->objFromFixture(File::class, 'disallowCanDelete');

        $response = Director::test(
            'admin/assets/api/delete',
            null,
            $this->session,
            'DELETE',
            http_build_query([
                'ids' => [$allowedFile->ID, $disallowedFile->ID],
                'SecurityID' => SecurityToken::inst()->getValue(),
            ])
        );
        $this->assertTrue($response->isError());

        $response = Director::test(
            'admin/assets/api/delete',
            null,
            $this->session,
            'DELETE',
            http_build_query([
                'ids' => [$allowedFile->ID],
                'SecurityID' => SecurityToken::inst()->getValue(),
            ])
        );
        $this->assertFalse($response->isError());
    }

    /**
     * @param array $params
     * @return array
     */
    protected function getResultsForSearch($params = array())
    {
        $query = array_merge(
            [ 'id' => 0 ],
            $params
        );
        $response = $this->get('admin/assets/api/readFolder?' . http_build_query($query));
        $this->assertFalse($response->isError());

        return json_decode($response->getBody(), true);
    }

    /**
     * @param string $paramName
     * @param string $tmpFileName
     * @return array Emulating an entry in the $_FILES superglobal
     */
    protected function getUploadFile($paramName, $tmpFileName = 'AssetAdminTest.txt')
    {
        $tmpFilePath = TEMP_FOLDER . '/' . $tmpFileName;
        $tmpFileContent = '';
        for ($i = 0; $i < 10000; $i++) {
            $tmpFileContent .= '0';
        }
        file_put_contents($tmpFilePath, $tmpFileContent);

        // emulates the $_FILES array
        return array(
            'name' => $tmpFileName,
            'type' => 'text/plaintext',
            'size' => filesize($tmpFilePath),
            'tmp_name' => $tmpFilePath,
            'error' => UPLOAD_ERR_OK,
        );
    }
}
