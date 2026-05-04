<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Whiteboard\Service;

use InvalidArgumentException;
use JsonException;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\GenericFileException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\Template\ITemplateManager;
use OCP\IConfig;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @psalm-suppress UndefinedDocblockClass
 * @psalm-suppress UndefinedClass
 * @psalm-suppress MissingDependency
 */
final class WhiteboardLibraryService {
	private const LIB_EXTENSION = '.excalidrawlib';
	private const GLOBAL_TEMPLATE_DISPLAY_PREFIX = 'Org library preset - ';
	private const GLOBAL_TEMPLATE_ID_PREFIX = 'organization-preset:';
	private const GLOBAL_TEMPLATE_DIR = 'global-libraries';
	private const MAX_FILENAME_BYTES = 250;

	public function __construct(
		private ITemplateManager $templateManager,
		private IRootFolder $rootFolder,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws NotPermittedException
	 * @throws NotFoundException
	 * @throws GenericFileException
	 * @throws LockedException
	 * @throws JsonException
	 */
	public function getUserLib(string $uid): array {
		if (str_starts_with($uid, 'shared_')) {
			return [];
		}

		$templateFolder = $this->getUserTemplateFolder($uid);
		$libs = [];

		foreach ($templateFolder->getDirectoryListing() as $node) {
			if (!$node instanceof File || !$this->isLibraryFileName($node->getName()) || str_starts_with($node->getName(), self::GLOBAL_TEMPLATE_DISPLAY_PREFIX)) {
				continue;
			}

			$libraryItems = $this->parseLibraryContent($node->getContent());
			if ($libraryItems === null) {
				$this->logger->warning('Skipping malformed whiteboard library preset', [
					'uid' => $uid,
					'file' => $node->getName(),
				]);
				continue;
			}

			$libs[] = [
				'type' => 'excalidrawlib',
				'version' => 2,
				'libraryItems' => $libraryItems,
				'basename' => $node->getName(),
				'filename' => $node->getName(),
			];
		}

		return $libs;
	}

	/**
	 * @throws NotPermittedException
	 * @throws NotFoundException
	 * @throws GenericFileException
	 * @throws LockedException
	 * @throws JsonException
	 */
	public function updateUserLib(string $uid, array $items): void {
		if (str_starts_with($uid, 'shared_')) {
			return;
		}

		$templatesFolder = $this->getUserTemplateFolder($uid);

		$files = [
			'personal.excalidrawlib' => [
				'type' => 'excalidrawlib',
				'version' => 2,
				'libraryItems' => [],
			],
		];

		foreach ($items as $item) {
			if (!isset($item['filename'])) {
				$files['personal.excalidrawlib']['libraryItems'][] = $item;
			} else {
				if (isset($files[$item['filename']])) {
					$files[$item['filename']]['libraryItems'][] = $item;
				} else {
					$files[$item['filename']] = [
						'type' => 'excalidrawlib',
						'version' => 2,
						'libraryItems' => [$item],
					];
				}
			}
		}

		foreach ($files as $filename => $fileData) {
			if ($templatesFolder->nodeExists($filename)) {
				$file = $templatesFolder->get($filename);
			} else {
				$file = $templatesFolder->newFile($filename);
			}

			if (!$file instanceof File) {
				throw new GenericFileException('Failed to create or get file: ' . $filename);
			}

			$file->putContent(json_encode($fileData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
		}
	}

	/**
	 * @throws NotPermittedException
	 * @throws NotFoundException
	 * @throws GenericFileException
	 * @throws LockedException
	 * @throws JsonException
	 */
	public function saveUserTemplate(string $uid, string $templateName, array $items): array {
		if (str_starts_with($uid, 'shared_')) {
			throw new InvalidArgumentException('Shared users cannot save library presets', Http::STATUS_BAD_REQUEST);
		}

		$templateFolder = $this->getUserTemplateFolder($uid);
		$normalizedName = $this->normalizeTemplateName($templateName);
		$currentFiles = $this->listUserLibraryFiles($templateFolder);
		$caseKey = $this->toCaseKey($normalizedName);

		if (isset($currentFiles[$caseKey])) {
			throw new RuntimeException('Library preset already exists', Http::STATUS_CONFLICT);
		}

		$normalizedItems = $this->normalizeLibraryItems($items);
		if ($normalizedItems === []) {
			throw new InvalidArgumentException('Library preset must contain at least one item', Http::STATUS_BAD_REQUEST);
		}
		if ($this->containsImageElement($normalizedItems)) {
			throw new InvalidArgumentException('This library contains image items that cannot be imported by Whiteboard yet.', Http::STATUS_BAD_REQUEST);
		}

		$this->writeUserTemplate($templateFolder, $normalizedName, $normalizedItems);

		return [
			'templateName' => $normalizedName,
			'itemCount' => count($normalizedItems),
		];
	}

	/**
	 * @throws NotPermittedException
	 * @throws GenericFileException
	 * @throws LockedException
	 */
	public function getGlobalTemplateMetadata(): array {
		return [
			'templates' => array_map(static fn (array $template): array => [
				'templateName' => $template['templateName'],
				'itemCount' => count($template['items']),
			], $this->listGlobalTemplates()['templates']),
		];
	}

	/**
	 * @return array<string, File>
	 *
	 * @throws NotPermittedException
	 * @throws GenericFileException
	 * @throws LockedException
	 */
	public function getGlobalTemplateFiles(): array {
		return $this->listGlobalTemplates()['files'];
	}

	/**
	 * @throws NotPermittedException
	 * @throws GenericFileException
	 * @throws LockedException
	 * @throws NotFoundException
	 */
	public function getGlobalTemplateFile(string $templateId): File {
		$normalizedName = $this->normalizeGlobalTemplateId($templateId);
		$files = $this->getGlobalTemplateFiles();
		$file = $files[$this->toCaseKey($normalizedName)] ?? null;
		if (!$file instanceof File) {
			throw new NotFoundException('Organization library preset not found');
		}
		return $file;
	}

	public function getGlobalTemplateId(string $templateName): string {
		return self::GLOBAL_TEMPLATE_ID_PREFIX . $this->toCaseKey($templateName);
	}

	public function getGlobalTemplateNameFromFileName(string $fileName): string {
		$name = $this->stripLibraryExtension($fileName);
		if (str_starts_with($name, self::GLOBAL_TEMPLATE_DISPLAY_PREFIX)) {
			$name = substr($name, strlen(self::GLOBAL_TEMPLATE_DISPLAY_PREFIX));
		}
		return $name;
	}

	/**
	 * @throws NotPermittedException
	 * @throws GenericFileException
	 * @throws LockedException
	 * @throws JsonException
	 */
	public function saveGlobalTemplateFromUpload(string $fileName, string $content): array {
		if (!$this->isLibraryFileName($fileName)) {
			throw new InvalidArgumentException('Upload an .excalidrawlib file', Http::STATUS_BAD_REQUEST);
		}

		$templateName = $this->normalizeTemplateName($fileName);
		$caseKey = $this->toCaseKey($templateName);
		$current = $this->listGlobalTemplates();

		if (isset($current['loadedFiles'][$caseKey])) {
			throw new RuntimeException('A library preset with this name already exists. Rename the file and upload it again.', Http::STATUS_CONFLICT);
		}

		$items = $this->parseLibraryContent($content);
		if ($items === null) {
			throw new InvalidArgumentException('This is not a valid Excalidraw library file.', Http::STATUS_BAD_REQUEST);
		}
		if ($items === []) {
			throw new InvalidArgumentException('This library has no reusable items. Upload a library with at least one item.', Http::STATUS_BAD_REQUEST);
		}
		if ($this->containsImageElement($items)) {
			throw new InvalidArgumentException('This library contains image items that cannot be imported by Whiteboard yet.', Http::STATUS_BAD_REQUEST);
		}

		$this->writeGlobalTemplate($this->getGlobalTemplateFolder(), $templateName, $items);

		return [
			'templateName' => $templateName,
			'itemCount' => count($items),
		];
	}

	/**
	 * @throws NotPermittedException
	 * @throws GenericFileException
	 * @throws LockedException
	 * @throws NotFoundException
	 */
	public function deleteGlobalTemplate(string $templateName): void {
		$normalizedName = $this->normalizeTemplateName($templateName);
		$current = $this->listGlobalTemplates();
		$fileName = $current['loadedFiles'][$this->toCaseKey($normalizedName)] ?? null;
		if (!is_string($fileName)) {
			throw new NotFoundException('Organization library preset not found');
		}

		$node = $this->getGlobalTemplateFolder()->get($fileName);
		if (!$node instanceof File) {
			throw new NotFoundException('Organization library preset not found');
		}
		$node->delete();
	}

	/**
	 * @return array<int, array<string,mixed>>|null
	 */
	public function parseLibraryContent(string $content): ?array {
		try {
			$data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}

		if (!is_array($data)) {
			return null;
		}

		if (array_key_exists('libraryItems', $data)) {
			return is_array($data['libraryItems']) ? $this->normalizeLibraryItems($data['libraryItems']) : null;
		}

		if (array_key_exists('library', $data)) {
			return is_array($data['library']) ? $this->normalizeLegacyLibraryItems($data['library']) : null;
		}

		if ($this->isListArray($data)) {
			return $this->normalizeLibraryItems($data);
		}

		return null;
	}

	/**
	 * @return array{templates: array<int, array{templateName: string, items: array}>, loadedFiles: array<string, string>, files: array<string, File>}
	 *
	 * @throws NotPermittedException
	 * @throws GenericFileException
	 * @throws LockedException
	 */
	private function listGlobalTemplates(): array {
		$templateFolder = $this->getGlobalTemplateFolder();
		$templates = [];
		$loadedFiles = [];
		$files = [];

		foreach ($templateFolder->getDirectoryListing() as $node) {
			if (!$node instanceof File || !$this->isLibraryFileName($node->getName())) {
				continue;
			}

			$templateName = $this->getGlobalTemplateNameFromFileName($node->getName());
			$caseKey = $this->toCaseKey($templateName);
			$loadedFiles[$caseKey] = $node->getName();
			$items = $this->parseLibraryContent($node->getContent());
			if ($items === null) {
				$this->logger->warning('Skipping malformed organization whiteboard library preset', [
					'file' => $node->getName(),
				]);
				continue;
			}

			$templates[] = [
				'templateName' => $templateName,
				'items' => $items,
			];
			$files[$caseKey] = $node;
		}

		usort($templates, static fn (array $left, array $right): int => strcasecmp($left['templateName'], $right['templateName']));

		return [
			'templates' => $templates,
			'loadedFiles' => $loadedFiles,
			'files' => $files,
		];
	}

	/**
	 * @throws NotPermittedException
	 */
	private function getGlobalTemplateFolder(): Folder {
		$instanceId = $this->config->getSystemValueString('instanceid', '');
		if ($instanceId === '') {
			throw new RuntimeException('No instance id configured');
		}

		$appDataRoot = $this->ensureChildFolder($this->rootFolder, 'appdata_' . $instanceId);
		$appFolder = $this->ensureChildFolder($appDataRoot, 'whiteboard');
		return $this->ensureChildFolder($appFolder, self::GLOBAL_TEMPLATE_DIR);
	}

	/**
	 * @throws NotPermittedException
	 * @throws NotFoundException
	 */
	private function getUserTemplateFolder(string $uid): Folder {
		if (!$this->templateManager->hasTemplateDirectory()) {
			$this->templateManager->initializeTemplateDirectory(null, $uid, false);
		}

		$userFolder = $this->rootFolder->getUserFolder($uid);
		$templatesPath = $this->templateManager->getTemplatePath();
		$templatesFolder = $userFolder->get($templatesPath);

		if (!$templatesFolder instanceof Folder) {
			throw new NotFoundException('Templates folder not found for user: ' . $uid);
		}

		return $templatesFolder;
	}

	/**
	 * @return array<string, string>
	 *
	 * @throws NotPermittedException
	 * @throws GenericFileException
	 * @throws LockedException
	 */
	private function listUserLibraryFiles(Folder $templateFolder): array {
		$loadedFiles = [];

		foreach ($templateFolder->getDirectoryListing() as $node) {
			if (!$node instanceof File || !$this->isLibraryFileName($node->getName())) {
				continue;
			}

			$templateName = $this->stripLibraryExtension($node->getName());
			if (str_starts_with($templateName, self::GLOBAL_TEMPLATE_DISPLAY_PREFIX)) {
				continue;
			}
			$loadedFiles[$this->toCaseKey($templateName)] = $node->getName();
		}

		return $loadedFiles;
	}

	/**
	 * @throws NotPermittedException
	 */
	private function ensureChildFolder(Folder $folder, string $name): Folder {
		if (!$folder->nodeExists($name)) {
			$folder->newFolder($name);
		}

		$node = $folder->get($name);
		if (!$node instanceof Folder) {
			throw new RuntimeException('Expected folder at ' . $name);
		}
		return $node;
	}

	/**
	 * @throws JsonException
	 */
	private function writeGlobalTemplate(Folder $templateFolder, string $templateName, array $items): void {
		$fileName = $this->toGlobalLibraryFileName($templateName);
		$this->writeTemplateFile($templateFolder, $fileName, $items);
	}

	/**
	 * @throws JsonException
	 */
	private function writeUserTemplate(Folder $templateFolder, string $templateName, array $items): void {
		$fileName = $this->toLibraryFileName($templateName);
		$this->writeTemplateFile($templateFolder, $fileName, $items);
	}

	/**
	 * @throws JsonException
	 */
	private function writeTemplateFile(Folder $templateFolder, string $fileName, array $items): void {
		$encoded = json_encode([
			'type' => 'excalidrawlib',
			'version' => 2,
			'libraryItems' => $this->normalizeLibraryItems($items),
		], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
		$file = $templateFolder->nodeExists($fileName)
			? $templateFolder->get($fileName)
			: $templateFolder->newFile($fileName);

		if (!$file instanceof File) {
			throw new GenericFileException('Failed to create or get file: ' . $fileName);
		}

		$file->putContent($encoded);
	}

	private function normalizeLegacyLibraryItems(array $libraries): array {
		$items = [];
		foreach ($libraries as $elements) {
			if (!is_array($elements) || count($elements) === 0) {
				continue;
			}
			$items[] = [
				'id' => $this->createLibraryItemId($elements),
				'created' => $this->nowMs(),
				'status' => 'published',
				'elements' => array_values($elements),
			];
		}
		return $this->normalizeLibraryItems($items);
	}

	private function normalizeLibraryItems(array $items): array {
		$normalized = [];
		foreach ($items as $item) {
			if (!is_array($item) || !isset($item['elements']) || !is_array($item['elements']) || count($item['elements']) === 0) {
				continue;
			}

			unset($item['templateName'], $item['scope'], $item['filename'], $item['basename']);
			$item['elements'] = array_values($item['elements']);
			$item['id'] = isset($item['id']) && is_string($item['id']) && $item['id'] !== ''
				? $item['id']
				: $this->createLibraryItemId($item['elements']);
			$item['created'] = isset($item['created']) && is_numeric($item['created'])
				? (int)$item['created']
				: $this->nowMs();
			$item['status'] = isset($item['status']) && is_string($item['status'])
				? $item['status']
				: 'unpublished';
			$normalized[] = $item;
		}
		return $normalized;
	}

	private function normalizeTemplateName(string $templateName): string {
		$name = trim($templateName);
		if ($this->isLibraryFileName($name)) {
			$name = trim($this->stripLibraryExtension($name));
		}
		if (str_starts_with($name, self::GLOBAL_TEMPLATE_DISPLAY_PREFIX)) {
			$name = trim(substr($name, strlen(self::GLOBAL_TEMPLATE_DISPLAY_PREFIX)));
		}

		if ($name === '' || $name === '.' || $name === '..') {
			throw new InvalidArgumentException('Invalid library preset name', Http::STATUS_BAD_REQUEST);
		}
		if (str_contains($name, '/') || str_contains($name, '\\') || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
			throw new InvalidArgumentException('Invalid library preset name', Http::STATUS_BAD_REQUEST);
		}
		if (strlen($this->toGlobalLibraryFileName($name)) > self::MAX_FILENAME_BYTES) {
			throw new InvalidArgumentException('Library preset name is too long', Http::STATUS_BAD_REQUEST);
		}

		return $name;
	}

	private function toLibraryFileName(string $templateName): string {
		return $templateName . self::LIB_EXTENSION;
	}

	private function toGlobalLibraryFileName(string $templateName): string {
		return self::GLOBAL_TEMPLATE_DISPLAY_PREFIX . $templateName . self::LIB_EXTENSION;
	}

	private function isLibraryFileName(string $fileName): bool {
		return str_ends_with(strtolower($fileName), self::LIB_EXTENSION);
	}

	private function stripLibraryExtension(string $fileName): string {
		return substr($fileName, 0, -strlen(self::LIB_EXTENSION));
	}

	private function toCaseKey(string $value): string {
		return strtolower($value);
	}

	private function normalizeGlobalTemplateId(string $templateId): string {
		if (!str_starts_with($templateId, self::GLOBAL_TEMPLATE_ID_PREFIX)) {
			throw new NotFoundException('Organization library preset not found');
		}
		$caseKey = substr($templateId, strlen(self::GLOBAL_TEMPLATE_ID_PREFIX));
		if ($caseKey === '' || str_contains($caseKey, '/') || str_contains($caseKey, '\\')) {
			throw new NotFoundException('Organization library preset not found');
		}
		$files = $this->listGlobalTemplates()['loadedFiles'];
		$fileName = $files[$caseKey] ?? null;
		if (!is_string($fileName)) {
			throw new NotFoundException('Organization library preset not found');
		}
		return $this->getGlobalTemplateNameFromFileName($fileName);
	}

	private function containsImageElement(array $items): bool {
		foreach ($items as $item) {
			if (!is_array($item) || !isset($item['elements']) || !is_array($item['elements'])) {
				continue;
			}
			foreach ($item['elements'] as $element) {
				if (is_array($element) && ($element['type'] ?? null) === 'image') {
					return true;
				}
			}
		}
		return false;
	}

	private function createLibraryItemId(array $elements): string {
		$encoded = json_encode($elements);
		return substr(hash('sha256', $encoded !== false ? $encoded : serialize($elements)), 0, 20);
	}

	private function nowMs(): int {
		return (int)floor((float)microtime(true) * 1000.0);
	}

	private function isListArray(array $value): bool {
		return $value === [] || array_keys($value) === range(0, count($value) - 1);
	}
}
