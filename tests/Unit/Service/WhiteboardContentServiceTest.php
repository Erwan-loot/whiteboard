<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Whiteboard\Service;

use OCP\Files\File;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class WhiteboardContentServiceTest extends TestCase {
	public function testNormalSaveKeepsEmbeddedLibraryItems(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(123);
		$file->method('getContent')->willReturn(json_encode([
			'elements' => [
				['id' => 'old-element', 'type' => 'rectangle'],
			],
			'files' => [],
			'libraryItems' => [
				[
					'id' => 'library-item-1',
					'elements' => [
						['id' => 'library-element-1', 'type' => 'ellipse'],
					],
				],
			],
			'scrollToContent' => true,
		], JSON_THROW_ON_ERROR));

		$file->expects($this->once())
			->method('putContent')
			->with($this->callback(static function (string $content): bool {
				$data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
				return $data['elements'][0]['id'] === 'new-element'
					&& $data['libraryItems'][0]['id'] === 'library-item-1'
					&& !isset($data['libraryMode'])
					&& !isset($data['librarySource']);
			}));

		$service = new WhiteboardContentService($this->createMock(LoggerInterface::class));
		$service->updateContent($file, [
			'data' => [
				'elements' => [
					['id' => 'new-element', 'type' => 'diamond'],
				],
				'files' => [],
				'scrollToContent' => true,
			],
		]);
	}
}
