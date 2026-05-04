<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Whiteboard\Listener;

use OCA\Whiteboard\Service\WhiteboardLibraryService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\Files\Template\ITemplateManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class FileCreatedFromTemplateListenerTest extends TestCase {
	public function testEmbedsOrganizationLibraryItemsIntoNewWhiteboard(): void {
		$template = $this->createMock(File::class);
		$template->method('getName')->willReturn('Org library preset - Flowchart.excalidrawlib');
		$template->method('getPath')->willReturn('/appdata_123/whiteboard/global-libraries/Org library preset - Flowchart.excalidrawlib');
		$template->method('getContent')->willReturn(json_encode([
			'type' => 'excalidrawlib',
			'version' => 2,
			'libraryItems' => [
				[
					'id' => 'item-1',
					'status' => 'published',
					'elements' => [
						['id' => 'element-1', 'type' => 'rectangle'],
					],
				],
			],
		], JSON_THROW_ON_ERROR));

		$target = $this->createMock(File::class);
		$target->method('getName')->willReturn('New whiteboard.whiteboard');
		$target->method('getPath')->willReturn('/admin/files/New whiteboard.whiteboard');
		$target->expects($this->once())
			->method('putContent')
			->with($this->callback(static function (string $content): bool {
				$data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
				return $data['elements'] === []
					&& $data['files'] === []
					&& $data['scrollToContent'] === true
					&& !isset($data['libraryMode'])
					&& !isset($data['librarySource'])
					&& count($data['libraryItems']) === 1
					&& $data['libraryItems'][0]['id'] === 'item-1';
			}));

		$listener = new FileCreatedFromTemplateListener(
			$this->createLibraryService(),
			$this->createMock(LoggerInterface::class),
		);
		$listener->handle(new FileCreatedFromTemplateEvent($template, $target, []));
	}

	public function testIgnoresUserLibraryTemplates(): void {
		$template = $this->createMock(File::class);
		$template->method('getName')->willReturn('My preset.excalidrawlib');
		$template->method('getPath')->willReturn('/admin/files/Templates/My preset.excalidrawlib');

		$target = $this->createMock(File::class);
		$target->method('getName')->willReturn('New whiteboard.whiteboard');
		$target->expects($this->never())->method('putContent');

		$listener = new FileCreatedFromTemplateListener(
			$this->createLibraryService(),
			$this->createMock(LoggerInterface::class),
		);
		$listener->handle(new FileCreatedFromTemplateEvent($template, $target, []));
	}

	private function createLibraryService(): WhiteboardLibraryService {
		return new WhiteboardLibraryService(
			$this->createMock(ITemplateManager::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(IConfig::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
