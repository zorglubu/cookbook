<?php

namespace OCA\Cookbook\tests\Unit\Helper;

use OCA\Cookbook\Exception\WrongFileTypeException;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\Files\Folder;
use OCP\Files\FileInfo;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\MockObject\Stub;
use OCA\Cookbook\Helper\FilesystemHelper;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

/**
 * @coversDefaultClass \OCA\Cookbook\Helper\FilesystemHelper
 * @covers \OCA\Cookbook\Helper\FilesystemHelper
 * @covers ::<private>
 * @covers ::<protected>
 * @covers \OCA\Cookbook\Exception\WrongFileTypeException
 */
class FilesystemHelperTest extends TestCase {
	
	/**
	 * @var MockObject|IRootFolder
	 */
	private $root;

	/**
	 * @var FilesystemHelper
	 */
	private $dut;

	protected function setUp(): void {
		parent::setUp();

		$this->root = $this->createMock(IRootFolder::class);
		/**
		 * @var MockObject|IL10N $l
		 */
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->dut = new FilesystemHelper($this->root, $l);
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor() {
		$cls = new ReflectionClass(FilesystemHelper::class);
		$prop = $cls->getProperty('root');
		$prop->setAccessible(true);

		$this->assertSame($this->root, $prop->getValue($this->dut));
	}

	public function dpCheckNodeExists() {
		yield ['FolderA', false];
		yield ['FolderA', true];
	}
	
	/**
	 * @dataProvider dpCheckNodeExists
	 * @covers ::nodeExists
	 */
	public function testCheckNodeExists($name, $exists) {
		/**
		 * @var Stub|Folder
		 */
		$folder = $this->createStub(Folder::class);
		$folder->method('nodeExists')->willReturn($exists);
		
		$this->assertEquals($exists, $this->dut->nodeExists($name, $folder));
	}

	/**
	 * @dataProvider dpCheckNodeExists
	 * @covers ::nodeExists
	 */
	public function testCheckNodeExistsInRoot($name, $exists) {
		$this->root->method('nodeExists')->willReturn($exists);
		
		$this->assertEquals($exists, $this->dut->nodeExists($name));
	}
	
	public function dpPermissions() {
		return [
			'no_access' => [
				0,
				false, false, false
			],
			'create_only' => [
				Constants::PERMISSION_CREATE,
				false, false, false
			],
			'delete_only' => [
				Constants::PERMISSION_DELETE,
				false, false, false
			],
			'read_only' => [
				Constants::PERMISSION_READ,
				true, false, false
			],
			'read_shared' => [
				Constants::PERMISSION_READ | Constants::PERMISSION_SHARE,
				true, false, false
			],
			'read_create' => [
				Constants::PERMISSION_READ | Constants::PERMISSION_CREATE,
				true, false, false
			],
			'read_delete' => [
				Constants::PERMISSION_READ | Constants::PERMISSION_DELETE,
				true, false, false
			],
			'read_write' => [
				Constants::PERMISSION_READ | Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE,
				true, true, true
			],
			'read_write_share' => [
				Constants::PERMISSION_READ | Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE | Constants::PERMISSION_SHARE,
				true, true, true
			],
			'write_only' => [
				Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE,
				false, true, false
			],
			'write_share' => [
				Constants::PERMISSION_CREATE | Constants::PERMISSION_DELETE | Constants::PERMISSION_SHARE,
				false, true, false
			],
		];
	}

	private function createPermissionFolderStub($permissions): Folder {
		/**
		 * @var Stub|Folder
		 */
		$folder = $this->createStub(Folder::class);
		$folder->method('getPermissions')->willReturn($permissions);

		return $folder;
	}
	
	/**
	 * @dataProvider dpPermissions
	 * @covers ::nodeHasReadPermissions
	 */
	public function testCheckReadPermissions($permissions, $expectedRead, $expectedWrite, $expectedAll) {
		$this->assertEquals(
			$expectedRead,
			$this->dut->nodeHasReadPermissions($this->createPermissionFolderStub($permissions))
		);
	}

	/**
	 * @dataProvider dpPermissions
	 * @covers ::folderHasWritePermissions
	 */
	public function testCheckWritePermissions($permissions, $expectedRead, $expectedWrite, $expectedAll) {
		$this->assertEquals(
			$expectedWrite,
			$this->dut->folderHasWritePermissions($this->createPermissionFolderStub($permissions))
		);
	}

	/**
	 * @dataProvider dpPermissions
	 * @covers ::folderHasFullPermissions
	 */
	public function testCheckAllPermissions($permissions, $expectedRead, $expectedWrite, $expectedAll) {
		$this->assertEquals(
			$expectedAll,
			$this->dut->folderHasFullPermissions($this->createPermissionFolderStub($permissions))
		);
	}

	private function getRootForEnsureNode($useRoot): MockObject {
		if ($useRoot) {
			$root = $this->root;
		} else {
			$root = $this->createStub(Folder::class);
		}
		
		return $root;
	}

	private function getNodeForEnsureNode($isFolder): Node {
		if ($isFolder) {
			/**
			 * @var Stub|Node $stub
			 */
			$stub = $this->createStub(Folder::class);
			$stub->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
			return $stub;
		} else {
			/**
			 * @var Stub|Node $stub
			 */
			$stub = $this->createStub(File::class);
			$stub->method('getType')->willReturn(FileInfo::TYPE_FILE);
			return $stub;
		}
	}

	private function ensureNodeExists(
		string $name,
		bool $useRoot,
		bool $nodeExists,
		string $newMethodName,
		string $dutMethodName,
		bool $isFolder,
		bool $creationSucceeds
	) {
		$root = $this->getRootForEnsureNode($useRoot);

		$node = $this->getNodeForEnsureNode($isFolder);

		$getCall = $root->expects($this->once())->method('get')->with($name);
		if ($nodeExists) {
			$getCall->willReturn($node);
		} else {
			$getCall->willThrowException(new NotFoundException());
		}

		if ($nodeExists) {
			$root->expects($this->never())->method($newMethodName);
		} else {
			$newCall = $root->expects($this->once())->method($newMethodName)->with($name);
			if ($creationSucceeds) {
				$newCall->willReturn($node);
			} else {
				$newCall->willThrowException(new NotPermittedException());
			}
		}

		if ($useRoot) {
			$ret = $this->dut->$dutMethodName($name);
		} else {
			$ret = $this->dut->$dutMethodName($name, $root);
		}

		$this->assertSame($node, $ret);
	}

	public function dpEnsureNodeExists() {
		return [
			['/Rezipes/foo', false, false],
			['/Rezipes/foo', false, true],
			['/Rezipes/foo', true, false],
			['/Rezipes/foo', true, true],
		];
	}

	/**
	 * @covers ::ensureFolderExists
	 * @dataProvider dpEnsureNodeExists
	 */
	public function testEnsureFolderExistsWithExistingNode($name, $useRoot, $isFolder) {
		try {
			$this->ensureNodeExists($name, $useRoot, true, 'newFolder', 'ensureFolderExists', $isFolder, false);
		} catch (WrongFileTypeException $ex) {
			$this->assertFalse($isFolder);
		}
	}

	/**
	 * @covers ::ensureFolderExists
	 * @dataProvider dpEnsureNodeExists
	 */
	public function testEnsureFolderExistsWithNonExistingNode($name, $useRoot, $isSuccess) {
		try {
			$this->ensureNodeExists($name, $useRoot, false, 'newFolder', 'ensureFolderExists', true, $isSuccess);
		} catch (NotPermittedException $ex) {
			$this->assertFalse($isSuccess);
		}
	}

	/**
	 * @covers ::ensureFileExists
	 * @dataProvider dpEnsureNodeExists
	 */
	public function testEnsureFileExistsWithExistingNode($name, $useRoot, $isFolder) {
		try {
			$this->ensureNodeExists($name, $useRoot, true, 'newFile', 'ensureFileExists', $isFolder, false);
		} catch (WrongFileTypeException $ex) {
			$this->assertTrue($isFolder);
		}
	}

	/**
	 * @covers ::ensureFileExists
	 * @dataProvider dpEnsureNodeExists
	 */
	public function testEnsureFileExistsWithNonExistingNode($name, $useRoot, $isSuccess) {
		try {
			$this->ensureNodeExists($name, $useRoot, false, 'newFile', 'ensureFileExists', false, $isSuccess);
		} catch (NotPermittedException $ex) {
			$this->assertFalse($isSuccess);
		}
	}


	public function dpIsFolder() {
		return [
			[true],
			[false],
		];
	}

	/**
	 * @dataProvider dpIsFolder
	 * @covers ::isFolder
	 */
	public function testIsFolder($isFolder) {
		/**
		 * @var Stub|Node
		 */
		$node = $this->createStub(Node::class);
		$type = $isFolder ? FileInfo::TYPE_FOLDER : FileInfo::TYPE_FILE;

		$node->method('getType')->willReturn($type);

		$this->assertEquals($isFolder, $this->dut->isFolder($node));
	}

	public function dpEnsureNodeDeleted() {
		return [
			['recipe.json', true],
			['recipe.json', false],
		];
	}

	private function ensureNodeDeleted(
		string $name,
		bool $useRoot,
		bool $nodeExists
	) {
		$root = $this->getRootForEnsureNode($useRoot);
		/**
		 * @var MockObject|File $node
		 */
		$node = $this->createMock(File::class);
		
		$root->expects($this->once())->method('nodeExists')->with($name)->willReturn($nodeExists);

		if ($nodeExists) {
			$root->expects($this->once())->method('get')->with($name)->willReturn($node);
			$node->expects($this->once())->method('delete');
		} else {
			$root->expects($this->never())->method('get');
		}

		$this->root = $root;
	}

	/**
	 * @dataProvider dpEnsureNodeDeleted
	 */
	public function testEnsureNodeDeletedRoot(string $name, bool $nodeExists) {
		$this->ensureNodeDeleted($name, true, $nodeExists);

		$this->dut->ensureNodeDeleted($name);
	}

	/**
	 * @dataProvider dpEnsureNodeDeleted
	 */
	public function testEnsureNodeDeletedNoRoot(string $name, bool $nodeExists) {
		$this->ensureNodeDeleted($name, false, $nodeExists);

		$this->dut->ensureNodeDeleted($name, $this->root);
	}
}