<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Whiteboard\Listener;

use OCA\Whiteboard\Service\WhiteboardLibraryService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<FileCreatedFromTemplateEvent|Event> */
/**
 * @psalm-suppress MissingTemplateParam
 */
final class FileCreatedFromTemplateListener implements IEventListener {
	private const LIB_EXTENSION = '.excalidrawlib';
	private const WHITEBOARD_EXTENSION = '.whiteboard';
	private const GLOBAL_TEMPLATE_DIR = '/whiteboard/global-libraries/';

	/**
	 * @psalm-suppress PossiblyUnusedMethod
	 */
	public function __construct(
		private WhiteboardLibraryService $libraryService,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof FileCreatedFromTemplateEvent)) {
			return;
		}

		$template = $event->getTemplate();
		$target = $event->getTarget();
		if (!($template instanceof File)) {
			return;
		}

		if (!$this->isOrganizationLibraryTemplate($template) || !$this->isWhiteboardTarget($target)) {
			return;
		}

		$libraryItems = $this->libraryService->parseLibraryContent($template->getContent());
		if ($libraryItems === null) {
			return;
		}

		try {
			$target->putContent(json_encode([
				'elements' => [],
				'files' => [],
				'libraryItems' => $libraryItems,
				'scrollToContent' => true,
			], JSON_THROW_ON_ERROR));
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to normalize whiteboard created from library preset', [
				'app' => 'whiteboard',
				'template' => $template->getPath(),
				'target' => $target->getPath(),
				'exception' => $e,
			]);
		}
	}

	private function isOrganizationLibraryTemplate(File $file): bool {
		return str_ends_with(strtolower($file->getName()), self::LIB_EXTENSION)
			&& str_contains($file->getPath(), self::GLOBAL_TEMPLATE_DIR);
	}

	private function isWhiteboardTarget(File $file): bool {
		return str_ends_with(strtolower($file->getName()), self::WHITEBOARD_EXTENSION);
	}
}
