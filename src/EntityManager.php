<?php
/**
 * User: Martin Chudoba
 * Date: 9.3.14
 * Time: 13:09
 */

namespace slimORM;

use Nette\Caching\Cache;
use Nette\Database\Context;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Parameter;
use Nette\Utils\FileSystem;
use slimORM\Exceptions\RepositoryException;
use slimORM\Reflexion\EntityReflexion;

/**
 * Class EntityManager
 * @package slimORM
 */
final class EntityManager {

	/** string PREFIX */
	const PREFIX = "__slimORM__";

	/** @var array */
	private $repositories;

	/** @var \Nette\Database\Context */
	private $connection;

	/** @var array */
	private $entities;

	/** @var \Nette\Caching\Cache */
	private $cache;

	/**
	 * EntityManager constructor.
	 * @param Context $connection
	 * @param Cache $cache
	 */
	public function __construct(Context $connection, Cache $cache) {
		$this->repositories = array();
		$this->entities = array();
		$this->connection = $connection;
		$this->cache = $cache;
	}

	/**
	 * @param $className
	 * @throws Exceptions\RepositoryException
	 * @return \slimORM\BaseRepository
	 */
	public function getRepository($className) {
		$genClassName = EntityManager::PREFIX . str_replace("\\", "", $className) . "Repository";
		if (array_key_exists($genClassName, $this->repositories)) {
			return $this->repositories[$genClassName];
		} else {
			if (class_exists($className)) {
				$this->generateRepository($className);
				$this->generateEntity($className);
				return $this->repositories[$genClassName];
			} else {
				throw new RepositoryException("Class \"" . $className . "\" is not exists.");
			}
		}
	}

	/**
	 * @param string $className
	 * @return array
	 */
	private function getColumns($className) {
		return EntityReflexion::getColumns($className);
	}

	/**
	 * @param string $className
	 * @return array
	 */
	private function getReferences($className) {
		return EntityReflexion::getReferences($className);
	}

	/**
	 * @param string $entityClassName
	 * @return string
	 */
	public function generateRepositoryName($entityClassName) {
		return EntityManager::PREFIX . str_replace("\\", "", $entityClassName) . "Repository";
	}

	/**
	 * Generate repository class
	 * @param $className
	 * @throws RepositoryException
	 * @throws \ErrorException
	 */
	private function generateRepository($className) {
		$table = EntityReflexion::getTable($className);
		if ($table === NULL) {
			throw new RepositoryException("Entity \"" . $className . " has no annotation \"table\"");
		} else {
			$genClassName = $this->generateRepositoryName($className);
			if (!class_exists($genClassName)) {
				$class = $this->cache->load($genClassName);
				if ($class) {
					$repository = $class;
				} else {
					$repository = new ClassType($genClassName);
					$repository->addExtend('\slimORM\BaseRepository');
					$repository->setFinal(TRUE);
					$repository->addComment($genClassName);
					$repository->addComment("@generated");
					$repository->addProperty("connection")
						->setVisibility("protected")
						->addComment('@var \Nette\Database\Context');
					$repository->addProperty("entityManager")
						->setVisibility("protected")
						->addComment('@var \slimORM\EntityManager');
					$parameter = new Parameter("connection");
					$parameter->setTypeHint('\Nette\Database\Context');
					$parameter2 = new Parameter("entityManager");
					$parameter2->setTypeHint('\slimORM\EntityManager');
					$entity = EntityManager::PREFIX . str_replace("\\", "", $className) . "Entity";
					$repository->addMethod("__construct")
						->addComment($genClassName . " constructor")
						->addComment('@param \Nette\Database\Context $connection')
						->addComment('@param \slimORM\EntityManager $entityManager')
						->setParameters(array($parameter, $parameter2))
						->setBody("\$this->connection = \$connection;\n\$this->entityManager = \$entityManager;\nparent::__construct(\$connection, \"$table\", \"$entity\");");
					$parameter = new Parameter("key");
					$repository->addMethod("get")
						->addComment("Find item by primary key")
						->addComment("@param int \$key")
						->addComment("@return $entity|null")
						->setParameters(array($parameter))
						->setBody("return parent::get(\$key);");

					//FileSystem::write(WWW_DIR . '/temp/' . $genClassName . ".php", "<?php\n" . $repository);
					$this->cache->save($genClassName, $repository, array(
						Cache::FILES => EntityReflexion::getFile($className), // lze uvést i pole souborů
					));
				}
				$res = eval('?><?php ' . $repository);
				if ($res === FALSE && ($error = error_get_last()) && $error['type'] === E_PARSE) {
					throw new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
				}
				$this->repositories[$genClassName] = new $genClassName($this->connection, $this);
			} else if (!isset($this->repositories[$genClassName])) {
				$this->repositories[$genClassName] = new $genClassName($this->connection, $this);
			}
		}
	}

	/**
	 * Generate entity class
	 * @param $className
	 * @throws RepositoryException
	 * @throws \ErrorException
	 */
	private function generateEntity($className)	{
		$genClassName = EntityManager::PREFIX . str_replace("\\", "", $className) . "Entity";
		$table = EntityReflexion::getTable($className);
		if ($table === NULL) {
			throw new RepositoryException("Entity \"" . $className . " has no annotation \"table\"");
		} else {
			if (in_array($genClassName, $this->entities) || class_exists($genClassName)) {
				return;
			} else {
				$this->entities[$genClassName] = $genClassName;
				$class = $this->cache->load($genClassName);
				if ($class) {
					$repository = $class;
					$references = $this->getReferences($className);
					$this->generateReferences($references);
				} else {
					$repository = new ClassType($genClassName);
					$repository->addExtend($className);
					$repository->setFinal(TRUE);
					$repository->addComment($genClassName);
					$repository->addComment("@table " . $table);

					$columns = $this->getColumns($className);
					$this->generateGetters($columns, $repository);

					$references = $this->getReferences($className);
					$this->generateReferences($references, $repository);
					$this->generateAddMethods($references, $repository);
					$this->generateOverrides($repository);

					//FileSystem::write(WWW_DIR . '/temp/' . $genClassName . ".php", "<?php\n" . $repository);
					$this->cache->save($genClassName, $repository, array(
						Cache::FILES => EntityReflexion::getFile($className), // lze uvést i pole souborů
					));
				}
				if (!class_exists($genClassName)) {
					$res = eval('?><?php ' . $repository);
					if ($res === FALSE && ($error = error_get_last()) && $error['type'] === E_PARSE) {
						throw new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
					}
				}
			}
		}
	}

	/**
	 * @param ClassType $repository
	 */
	private function generateOverrides(ClassType $repository) {
		$repository->addMethod("getReferences")
			->addComment("@return array")
			->addComment('@throws Exception\EntityException')
			->setBody("if (count(\$this->references) == 0) {\n\t\$references = parent::getReferences();\n\n\tforeach (\$references as &\$ref) {\n\t\t\$ref->targetEntity = \"" . EntityManager::PREFIX . "\" . str_replace(\"\\\\\", \"\", \$ref->targetEntity) . \"Entity\";\n\t}\n}\n\nreturn \$this->references;");
	}

	/**
	 * @param array $columns
	 * @param ClassType $repository
	 */
	private function generateGetters(array $columns, ClassType $repository)	{
		foreach ($columns as $column) {
			$name = $column['name'];
			$method = $repository->addMethod("get" . ucfirst($name))
				->setBody("return \$this->$name;");
				//->setBody("if (\$this->row && \$this->$name === NULL) {\n" . "\t" . "\$this->$name = \$this->row->$name;\n}\n\nreturn \$this->$name;");
			foreach ($column['annotations'] as $key => $doc) {
				if ($key == "var") {
					$return = implode(" ", $doc);
					$method->addComment("@return " . $return);
					break;
				}
			}
		}
	}

	/**
	 * @param array $references
	 * @param ClassType $repository
	 */
	private function generateReferences(array $references, ClassType $repository = NULL) {
		foreach ($references as $ref) {
			$body = $phpDoc = "";
			$genClassName = EntityManager::PREFIX . str_replace("\\", "", $ref->targetEntity) . "Entity";
			switch ($ref->linkage) {
				case 'OneToMany':
				case 'ManyToMany':
					$this->generateEntity($ref->targetEntity);
					if ($repository) {
						$phpDoc = "@return " . $ref->targetEntity . "[]";
						$body = "if (\$this->row && \$this->$ref->property === NULL) {\n\tif (\$this->row->getTable()->getPrimary(TRUE) === \"" . $ref->key . "\" || \"" . EntityReflexion::getTable($ref->targetEntity) . "\" == \\slimORM\\Reflexion\\EntityReflexion::getTable(get_class(\$this)) ) {\n\t\t\$this->" . $ref->property . " = \$this->oneToMany(\"" . $ref->property . "\", \"" . EntityReflexion::getTable($ref->targetEntity) . "\", \"" . $ref->key . "\", \"" . $ref->targetEntity . "\"";
						$body .= ");\n\t} else {\n\t\t\$this->" . $ref->property ." = \$this->oneToOne(\"" . $ref->property . "\", \"" . EntityReflexion::getTable($ref->targetEntity) . "\", \"" . $ref->key . "\", \"" . $genClassName . "\");\n\t}\n}\nreturn \$this->" . $ref->property . ";";
					}
					break;
				case 'OneToOne':
				case 'ManyToOne':
					$this->generateEntity($ref->targetEntity);
					if ($repository) {
						$phpDoc = "@return " . $ref->targetEntity;
						$body = "if (\$this->row && \$this->$ref->property === NULL) {\n\tif (\$this->row->getTable()->getPrimary(TRUE) === \"" . $ref->key . "\") {\n\t\t\$this->" . $ref->property . " = \$this->manyToOne(\"" . $ref->property . "\", \"" . EntityReflexion::getTable($ref->targetEntity) . "\", \"" . $ref->key . "\", \"" . $genClassName . "\"";
						$body .= ");\n\t} else {\n\t\t\$this->" . $ref->property ." = \$this->oneToOne(\"" . $ref->property . "\", \"" . EntityReflexion::getTable($ref->targetEntity) . "\", \"" . $ref->key . "\", \"" . $genClassName . "\");\n\t}\n}\nreturn \$this->" . $ref->property . ";";
					}
					break;
			}
			if ($repository) {
				$repository->addMethod("get" . ucfirst($ref->property))
					->addComment($phpDoc)
					->setBody($body);
			}
		}
	}

	/**
	 * @param array $references
	 * @param ClassType $repository
	 */
	private function generateAddMethods(array $references, ClassType $repository) {
		foreach ($references as $ref) {
			if ($ref->linkage == "OneToMany") {
				$body = "if (\$this->" . $ref->property . " === null) {\n\t\$this->" . $ref->property . " = clone \$this->entityManager->getRepository('" . $ref->targetEntity . "');\n\t";
				$body .= "if (\$this->toRow() === null) {\n\t\t\$this->" . $ref->property . "->clear();\n\t}\n}\n\$this->get" . ucfirst($ref->property) . "()->push(\$obj);\nreturn \$this;";
				$parameter = new Parameter("obj");
				$parameter->setTypeHint($ref->targetEntity);
				$repository->addMethod("add" . ucfirst($ref->property))
					->addComment("@param " . $ref->targetEntity . " \$obj")
					->addComment("@return \$this")
					->addComment('@throws \slimORM\Exceptions\RepositoryException')
					->setParameters(array($parameter))
					->setBody($body);
			}
		}

	}
} 