<?php declare(strict_types = 1);

namespace PHPStan\Testing;

use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierFactory;
use PHPStan\Broker\AnonymousClassNameHelper;
use PHPStan\Broker\Broker;
use PHPStan\Broker\BrokerFactory;
use PHPStan\Cache\Cache;
use PHPStan\Cache\MemoryCacheStorage;
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\File\FileHelper;
use PHPStan\File\FuzzyRelativePathHelper;
use PHPStan\Parser\FunctionCallStatementFinder;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\PhpDocStringResolver;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Reflection\Annotations\AnnotationsMethodsClassReflectionExtension;
use PHPStan\Reflection\Annotations\AnnotationsPropertiesClassReflectionExtension;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionReflectionFactory;
use PHPStan\Reflection\Php\PhpClassReflectionExtension;
use PHPStan\Reflection\Php\PhpFunctionReflection;
use PHPStan\Reflection\Php\PhpMethodReflection;
use PHPStan\Reflection\Php\PhpMethodReflectionFactory;
use PHPStan\Reflection\Php\UniversalObjectCratesClassReflectionExtension;
use PHPStan\Reflection\PhpDefect\PhpDefectClassReflectionExtension;
use PHPStan\Reflection\SignatureMap\SignatureMapProvider;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\Type;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{

	/** @var Container|null */
	private static $container;

	public static function getContainer(): Container
	{
		if (self::$container === null) {
			$tmpDir = sys_get_temp_dir() . '/phpstan-tests';
			if (!@mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
				self::fail(sprintf('Cannot create temp directory %s', $tmpDir));
			}

			$rootDir = __DIR__ . '/../..';
			$containerFactory = new ContainerFactory($rootDir);
			self::$container = $containerFactory->create($tmpDir, [
				$containerFactory->getConfigDirectory() . '/config.level7.neon',
			], []);
		}

		return self::$container;
	}

	public function getParser(): \PHPStan\Parser\Parser
	{
		/** @var \PHPStan\Parser\Parser $parser */
		$parser = self::getContainer()->getService('directParser');
		return $parser;
	}

	/**
	 * @param \PHPStan\Type\DynamicMethodReturnTypeExtension[] $dynamicMethodReturnTypeExtensions
	 * @param \PHPStan\Type\DynamicStaticMethodReturnTypeExtension[] $dynamicStaticMethodReturnTypeExtensions
	 * @return \PHPStan\Broker\Broker
	 */
	public function createBroker(
		array $dynamicMethodReturnTypeExtensions = [],
		array $dynamicStaticMethodReturnTypeExtensions = []
	): Broker
	{
		$functionCallStatementFinder = new FunctionCallStatementFinder();
		$parser = $this->getParser();
		$cache = new Cache(new MemoryCacheStorage());
		$methodReflectionFactory = new class($parser, $functionCallStatementFinder, $cache) implements PhpMethodReflectionFactory {

			/** @var \PHPStan\Parser\Parser */
			private $parser;

			/** @var \PHPStan\Parser\FunctionCallStatementFinder */
			private $functionCallStatementFinder;

			/** @var \PHPStan\Cache\Cache */
			private $cache;

			/** @var \PHPStan\Broker\Broker */
			public $broker;

			public function __construct(
				Parser $parser,
				FunctionCallStatementFinder $functionCallStatementFinder,
				Cache $cache
			)
			{
				$this->parser = $parser;
				$this->functionCallStatementFinder = $functionCallStatementFinder;
				$this->cache = $cache;
			}

			/**
			 * @param ClassReflection $declaringClass
			 * @param ClassReflection|null $declaringTrait
			 * @param \PHPStan\Reflection\Php\BuiltinMethodReflection $reflection
			 * @param TemplateTypeMap $templateTypeMap
			 * @param Type[] $phpDocParameterTypes
			 * @param Type|null $phpDocReturnType
			 * @param Type|null $phpDocThrowType
			 * @param string|null $deprecatedDescription
			 * @param bool $isDeprecated
			 * @param bool $isInternal
			 * @param bool $isFinal
			 * @return PhpMethodReflection
			 */
			public function create(
				ClassReflection $declaringClass,
				?ClassReflection $declaringTrait,
				\PHPStan\Reflection\Php\BuiltinMethodReflection $reflection,
				TemplateTypeMap $templateTypeMap,
				array $phpDocParameterTypes,
				?Type $phpDocReturnType,
				?Type $phpDocThrowType,
				?string $deprecatedDescription,
				bool $isDeprecated,
				bool $isInternal,
				bool $isFinal
			): PhpMethodReflection
			{
				return new PhpMethodReflection(
					$declaringClass,
					$declaringTrait,
					$reflection,
					$this->broker,
					$this->parser,
					$this->functionCallStatementFinder,
					$this->cache,
					$templateTypeMap,
					$phpDocParameterTypes,
					$phpDocReturnType,
					$phpDocThrowType,
					$deprecatedDescription,
					$isDeprecated,
					$isInternal,
					$isFinal
				);
			}

		};
		$phpDocStringResolver = self::getContainer()->getByType(PhpDocStringResolver::class);
		$currentWorkingDirectory = $this->getCurrentWorkingDirectory();
		$fileTypeMapper = new FileTypeMapper($parser, $phpDocStringResolver, $cache, new AnonymousClassNameHelper(new FileHelper($currentWorkingDirectory), new FuzzyRelativePathHelper($currentWorkingDirectory, DIRECTORY_SEPARATOR, [])), self::getContainer()->getByType(\PHPStan\PhpDoc\TypeNodeResolver::class));
		$annotationsMethodsClassReflectionExtension = new AnnotationsMethodsClassReflectionExtension($fileTypeMapper);
		$annotationsPropertiesClassReflectionExtension = new AnnotationsPropertiesClassReflectionExtension($fileTypeMapper);
		$signatureMapProvider = self::getContainer()->getByType(SignatureMapProvider::class);
		$phpExtension = new PhpClassReflectionExtension(self::getContainer(), $methodReflectionFactory, $fileTypeMapper, $annotationsMethodsClassReflectionExtension, $annotationsPropertiesClassReflectionExtension, $signatureMapProvider, $parser, true);
		$functionReflectionFactory = new class($this->getParser(), $functionCallStatementFinder, $cache) implements FunctionReflectionFactory {

			/** @var \PHPStan\Parser\Parser */
			private $parser;

			/** @var \PHPStan\Parser\FunctionCallStatementFinder */
			private $functionCallStatementFinder;

			/** @var \PHPStan\Cache\Cache */
			private $cache;

			public function __construct(
				Parser $parser,
				FunctionCallStatementFinder $functionCallStatementFinder,
				Cache $cache
			)
			{
				$this->parser = $parser;
				$this->functionCallStatementFinder = $functionCallStatementFinder;
				$this->cache = $cache;
			}

			/**
			 * @param \ReflectionFunction $function
			 * @param TemplateTypeMap $templateTypeMap
			 * @param Type[] $phpDocParameterTypes
			 * @param Type|null $phpDocReturnType
			 * @param Type|null $phpDocThrowType
			 * @param string|null $deprecatedDescription
			 * @param bool $isDeprecated
			 * @param bool $isInternal
			 * @param bool $isFinal
			 * @param string|false $filename
			 * @return PhpFunctionReflection
			 */
			public function create(
				\ReflectionFunction $function,
				TemplateTypeMap $templateTypeMap,
				array $phpDocParameterTypes,
				?Type $phpDocReturnType,
				?Type $phpDocThrowType,
				?string $deprecatedDescription,
				bool $isDeprecated,
				bool $isInternal,
				bool $isFinal,
				$filename
			): PhpFunctionReflection
			{
				return new PhpFunctionReflection(
					$function,
					$this->parser,
					$this->functionCallStatementFinder,
					$this->cache,
					$templateTypeMap,
					$phpDocParameterTypes,
					$phpDocReturnType,
					$phpDocThrowType,
					$deprecatedDescription,
					$isDeprecated,
					$isInternal,
					$isFinal,
					$filename
				);
			}

		};

		$currentWorkingDirectory = $this->getCurrentWorkingDirectory();
		$anonymousClassNameHelper = new AnonymousClassNameHelper(new FileHelper($currentWorkingDirectory), new FuzzyRelativePathHelper($currentWorkingDirectory, DIRECTORY_SEPARATOR, []));
		$broker = new Broker(
			[
				$phpExtension,
				new PhpDefectClassReflectionExtension(self::getContainer()->getByType(TypeStringResolver::class), $annotationsPropertiesClassReflectionExtension),
				new UniversalObjectCratesClassReflectionExtension([\stdClass::class]),
				$annotationsPropertiesClassReflectionExtension,
			],
			[
				$phpExtension,
				$annotationsMethodsClassReflectionExtension,
			],
			array_merge(self::getContainer()->getServicesByTag(BrokerFactory::DYNAMIC_METHOD_RETURN_TYPE_EXTENSION_TAG), $dynamicMethodReturnTypeExtensions, $this->getDynamicMethodReturnTypeExtensions()),
			array_merge(self::getContainer()->getServicesByTag(BrokerFactory::DYNAMIC_STATIC_METHOD_RETURN_TYPE_EXTENSION_TAG), $dynamicStaticMethodReturnTypeExtensions, $this->getDynamicStaticMethodReturnTypeExtensions()),
			array_merge(self::getContainer()->getServicesByTag(BrokerFactory::DYNAMIC_FUNCTION_RETURN_TYPE_EXTENSION_TAG), $this->getDynamicFunctionReturnTypeExtensions()),
			$this->getOperatorTypeSpecifyingExtensions(),
			$functionReflectionFactory,
			new FileTypeMapper($this->getParser(), $phpDocStringResolver, $cache, $anonymousClassNameHelper, self::getContainer()->getByType(\PHPStan\PhpDoc\TypeNodeResolver::class)),
			$signatureMapProvider,
			self::getContainer()->getByType(Standard::class),
			$anonymousClassNameHelper,
			self::getContainer()->getByType(Parser::class),
			new FuzzyRelativePathHelper($this->getCurrentWorkingDirectory(), DIRECTORY_SEPARATOR, []),
			self::getContainer()->getParameter('universalObjectCratesClasses')
		);
		$methodReflectionFactory->broker = $broker;

		return $broker;
	}

	public function createScopeFactory(Broker $broker, TypeSpecifier $typeSpecifier): ScopeFactory
	{
		$container = self::getContainer();

		return new ScopeFactory(
			Scope::class,
			$broker,
			new \PhpParser\PrettyPrinter\Standard(),
			$typeSpecifier,
			$container->getByType(Container::class)
		);
	}

	public function getCurrentWorkingDirectory(): string
	{
		return $this->getFileHelper()->normalizePath(__DIR__ . '/../..');
	}

	/**
	 * @return \PHPStan\Type\DynamicMethodReturnTypeExtension[]
	 */
	public function getDynamicMethodReturnTypeExtensions(): array
	{
		return [];
	}

	/**
	 * @return \PHPStan\Type\DynamicStaticMethodReturnTypeExtension[]
	 */
	public function getDynamicStaticMethodReturnTypeExtensions(): array
	{
		return [];
	}

	/**
	 * @return \PHPStan\Type\DynamicFunctionReturnTypeExtension[]
	 */
	public function getDynamicFunctionReturnTypeExtensions(): array
	{
		return [];
	}

	/**
	 * @return \PHPStan\Type\OperatorTypeSpecifyingExtension[]
	 */
	public function getOperatorTypeSpecifyingExtensions(): array
	{
		return [];
	}

	/**
	 * @param \PhpParser\PrettyPrinter\Standard $printer
	 * @param \PHPStan\Broker\Broker $broker
	 * @param \PHPStan\Type\MethodTypeSpecifyingExtension[] $methodTypeSpecifyingExtensions
	 * @param \PHPStan\Type\StaticMethodTypeSpecifyingExtension[] $staticMethodTypeSpecifyingExtensions
	 * @return \PHPStan\Analyser\TypeSpecifier
	 */
	public function createTypeSpecifier(
		Standard $printer,
		Broker $broker,
		array $methodTypeSpecifyingExtensions = [],
		array $staticMethodTypeSpecifyingExtensions = []
	): TypeSpecifier
	{
		return new TypeSpecifier(
			$printer,
			$broker,
			self::getContainer()->getServicesByTag(TypeSpecifierFactory::FUNCTION_TYPE_SPECIFYING_EXTENSION_TAG),
			$methodTypeSpecifyingExtensions,
			$staticMethodTypeSpecifyingExtensions
		);
	}

	public function getFileHelper(): FileHelper
	{
		return self::getContainer()->getByType(FileHelper::class);
	}

	protected function skipIfNotOnWindows(): void
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			return;
		}

		self::markTestSkipped();
	}

	protected function skipIfNotOnUnix(): void
	{
		if (DIRECTORY_SEPARATOR === '/') {
			return;
		}

		self::markTestSkipped();
	}

}
