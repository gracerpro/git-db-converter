<?php

$fetcher = new FetchGitHistory();
$fetcher->generate();



class FetchGitHistory
{
    /**
     * @var Options
     */
    private $options;

    public function __construct()
    {
        $this->options = new Options();
        $this->readOptions();
        $this->checkOptions();
    }

    private function readOptions()
    {
        global $argv;
        $map = [
            'table' => 'tableName',
            'repo' => 'repo',
            'service' => 'service',
            'dir' => 'projectDir',
            'o' => 'outputFileName',
        ];
        // --a=1 --b --c=string
        if (isset($argv)) {
            for ($i = 1; $i < count($argv); ++$i) {
                $arg = $argv[$i];
                $argEmploded = explode('=', $arg);
                if (count($argEmploded) > 1) {
                    $key = trim($argEmploded[0], ' -');
                    $value = trim($argEmploded[1]);
                } else {
                    $key = trim($arg, ' -');
                    $value = true;
                }
                $propertyName = isset($map[$key]) ? $map[$key] : '';
                if (property_exists($this->options, $propertyName)) {
                    $this->options->{$propertyName} = $value;
                }
            }
        }
    }

    private function checkOptions()
    {
        if (empty($this->options->repo)) {
            throw new FetchGitHistoryException('Empty repo');
        }
        if (empty($this->options->service)) {
            throw new FetchGitHistoryException('Empty service');
        }
        if (empty($this->options->tableName)) {
            throw new FetchGitHistoryException('Empty table name');
        }
    }

    private function prepare()
    {
        if ($this->options->projectDir && is_dir($this->options->projectDir)) {
            chdir($this->options->projectDir);
        }
    }

    public function generate()
    {
        $this->prepare();

        $command = 'git log --no-merges --date=short';
        $fields = [
            'sha1' => 'H',
            'autor_email' => 'ae',
            'autor_name' => 'an',
            'autor_date' => 'ad',
            'subject' => 's',
        ];
        $format = '';
        foreach ($fields as $fieldName => $formatOption) {
            $format .= '\"' . $fieldName . '\":\"%' . $formatOption . '\",';
        }
        $fullFormat = '{' . substr($format, 0, -1) . '}';

        $queryCommand = "$command --pretty=format:\"$fullFormat\"";

        $output = [];
        $status = 0;
        exec($queryCommand, $output, $status);

        if ($status) {
            throw new FetchGitHistoryException('Wrong exec status ' . $status);
        }
        $commits = [];
        foreach ($output as $i => $line) {
            //echo $line; die;
            $commitData = json_decode($line, true);
            $commit = new Commit();
            if ($commitData) {
                $commit->sha1 = $commitData['sha1'];
                $commit->subject = $commitData['subject'];
                $commit->autorDate = $commitData['autor_date'];
                $commit->autorEmail = $commitData['autor_email'];
                $commit->autorName = $commitData['autor_name'];
            } else {
                $commit->errorMessage = $i . ' json_decode failed: ' . json_last_error_msg();
            }
            $commits[] = $commit;
        }

        $view = new View($this->options, $commits);
        $view->display();
    }
}

class FetchGitHistoryException extends \Exception {}

class Commit
{
    public $sha1;
    public $autorName;
    public $autorEmail;
    public $autorDate;
    public $subject;

    public $errorMessage;
}

class View
{
    private $commits = [];

    /**
     * @var Options
     */
    private $options;

    /**
     * @param Options $options
     * @param Commit[] $commits
     */
    public function __construct($options, $commits)
    {
        $this->options = $options;
        $this->commits = $commits;
    }

    public function display()
    {
        if (empty($this->commits)) {
            return false;
        }
        $this->printClearTable();

        echo "INSERT INTO `{$this->options->tableName}`(`service`, `repo`, `hash`, `date`, `message`, `author`) VALUES\n";
        $count = count($this->commits);
        foreach ($this->commits as $i => $commit) {
            // service, repo, hash, date, message, url, author
            if (empty($commit->subject)) {
                continue;
            }
            echo "('{$this->options->service}', '{$this->options->repo}', '{$commit->sha1}', ";
            echo "'{$commit->autorDate}', '{$this->escape($commit->subject)}', '{$commit->autorName}')";
            if ($i < $count - 1) {
                echo ",\n";
            } else {
                echo ";\n";
            }
        }

        echo "\n\n";
    }

    private function escape($str)
    {
        return addslashes($str);
    }

    private function printClearTable()
    {
        echo "DELETE FROM `{$this->options->tableName}`"
        . " WHERE `service` = '{$this->options->service}' AND `repo` = '{$this->options->repo}';\n\n";
    }
}

class Options
{
    /** @var string */
    public $tableName;

    /** @var string */
    public $service;

    /** @var string */
    public $repo;

    /** @var string */
    public $outputFileName;

    /** @var string */
    public $projectDir;
}
