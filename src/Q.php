<?php namespace Yuptogun;

/**
 * PDO 커넥션을 넣어주면 쿼리문을 만들고 실행해 줍니다.
 */
class Q
{
    // 쿼리를 때릴 최종 SQL 구문
    public $Q;

    // PDO 커넥션을 담는 변수
    public $db;

    // select(), insert() 등의 메소드로부터 전달받는 테이블 이름
    private $table;

    // get() 이후의 처리를 위해 메소드를 별도 저장해 둡니다.
    private $method;

    /**
     * 생성자
     *
     * @param $db 슬림 의존성 컨테이너에 들어있는 DB를 넣어줍니다.
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * $this->Q 를 실행해 결과를 돌려줍니다.
     *
     * @return mixed SELECT 일 때는 결과배열, INSERT 일 때는 삽입된 레코드의 ID, 나머지는 성공 여부
     */
    public function get()
    {
        $query = $this->db->prepare($this->Q);
        $executed = $query->execute();

        if (!$executed) return false;

        switch ($this->method) {
            case 'SELECT':      $return = $query->fetchAll();        break;
            case 'INSERT INTO': $return = $this->db->lastInsertId(); break;
            default:            $return = (bool) $executed;          break;
        }

        // 일단 쿼리를 실행했으면 DB 커넥션만 빼고 클래스변수들을 싹 비워준다.
        // 그래야 일일이 new QueryBuilder() 할필요없이 재사용 가능.
        $vars = get_object_vars($this);
        foreach ($vars as $key => $val) if ($key !== 'db') $this->$key = null;

        return $return;
    }
    /**
     * alias
     */
    public function run() { return $this->get(); }

    /**
     * 기본 SELECT 초기화
     *
     * @param string $table 테이블명
     * @param array $fields 찾아볼 필드들
     * @return self
     * @todo replaceOrAppend() 적용
     */
    public function select(string $table, array $fields = ['*'])
    {
        $this->table = $table;
        $this->method = 'SELECT';
        $this->Q = implode(' ', [
            $this->method,
            $this->implodeOrAppend($fields),
            'FROM',
            $this->table,
            $this->Q
        ]);
        return $this;
    }

    /**
     * 기본 INSERT
     *
     * @param string $table 추가할 테이블명
     * @param array $keysAndValues 필드 -> 값 배열
     * @return self
     * @todo replaceOrAppend() 적용
     */
    public function insert(string $table, array $keysAndValues)
    {
        $this->table = $table;
        $this->method = 'INSERT INTO';
        $this->Q = implode(' ', [
            $this->method,
            $this->table,
            '('.implode(', ', array_keys($keysAndValues)).')',
            'VALUES',
            '(\''.implode('\', \'', array_values($keysAndValues)).'\')',
            $this->Q
        ]);
        return $this;
    }

    /**
     * 기본 UPDATE
     *
     * @param string $table 추가할 테이블명
     * @param array $wheres 뭘 업데이트할지의 배열
     * @param array $keysAndValues 필드 -> 값 배열
     * @return self
     * @todo replaceOrAppend() 적용
     */
    public function update(string $table, array $wheres, array $keysAndValues)
    {
        $this->table = $table;
        $this->method = 'UPDATE';
        $this->Q = implode(' ', [
            $this->method,
            $this->table,
            'SET',
            $this->formatSet($keysAndValues),
        ]);
        return $this->where($wheres);
    }
    /**
     * update() 메소드가 사용하는 헬퍼 함수
     *
     * @param array $arg
     * @return string column1=value, column2=value2, 등으로 적당히 만들어준다.
     */
    protected function formatSet(array $arg)
    {
        $sets = [];
        foreach ($arg as $key => $value) $sets[] = $this->formatWhere($key, $value);
        return implode(', ', $sets);
    }

    /**
     * 변수가 0~3개일 때에 대해서 각각 WHERE 구문을 만들어 추가합니다.
     *
     * @return self
     */
    public function where()
    {
        $args = func_get_args();
        $wheres = [];

        /**
         * case 3 : where('foo', '>=', 13)
         * case 2 : where('foo', 'bar')
         * case 1 : where(['foo' => 'bar', 'dee' => '>= 13'])
         * 그밖에 : 없음
         */
        switch (count($args)) {
            case 3:
                $wheres[] = $this->formatWhere($args);
                break;

            case 2:
                $wheres[] = $this->formatWhere($args[0], $args[1]);
                break;

            case 1:
                foreach ($args as $arg) foreach ($arg as $key => $value) $wheres[] = $this->formatWhere($key, $value);
                break;

            default:
                return $this;
                break;
        }

        $where = count($wheres) > 1 ? '('.implode(' AND ', $wheres).')' : $wheres[0];
        $this->Q .= ' WHERE '.$where;
        return $this;
    }
    protected function formatWhere()
    {
        $args = func_get_args();

        // MySQL 쿼리문은 숫자가 아니면 무조건 따옴표로 감싸줘야 한다.
        $args[count($args) - 1] = is_numeric($args[count($args) - 1]) ? $args[count($args) - 1] : '"'.$args[count($args) - 1].'"';

        switch (count($args)) {
            case 3: return implode(' ', $args);                     break;
            case 2: return implode(' ', [$args[0], '=', $args[1]]); break;
            case 1: return $args[0];                                break;
            default: return null;                                   break;
        }
    }

    /**
     * ORDER BY 구문을 추가합니다.
     *
     * @param string $field 기본값은 updated_at
     * @param string $direction 기본값은 desc
     * @return self
     */
    public function orderBy(string $field = 'updated_at', string $direction = 'desc')
    {
        $direction = strtoupper($direction);
        if (in_array($direction, ['ASC', 'DESC'])) {
            $pattern = '/ ORDER BY .+ (?:ASC|DESC)/i';
            $replace = ' ORDER BY '.$field.' '.$direction;
            $this->Q = $this->replaceOrAppend($pattern, $replace);
        }
        return $this;
    }

    /**
     * LIMIT 숫자 OFFSET 숫자 구문을 추가합니다. "페이징"입니다.
     *
     * @param integer $pageNum 몇 번째 페이지를 보여줘야 하나요?
     * @param integer $limit 한 페이지에 몇 개 나와야 하나요?
     * @return self
     */
    public function page(int $pageNum, int $limit)
    {
        $regex = [
            '/ LIMIT \d+/i' => ' LIMIT '.$limit,
            '/ OFFSET \d+/i' => ' OFFSET '.($limit * ($pageNum - 1)),
        ];
        foreach ($regex as $p => $r) $this->replaceOrAppend($p, $r);
        return $this;
    }

    public function first(int $limit = 1)
    {
        return $this->page(1, $limit);
    }
    
    /**
     * 텍스트를 고치거나 뒤에 붙입니다.
     *
     * @param mixed $p regex 구문 또는 그 배열
     * @param mixed $r regex 구문 또는 그 배열
     * @param string $s 검사 및 수정할 대상 문자열. 기본값은 $this->Q
     * @return string 적당히 바뀐 문자열
     */
    protected function replaceOrAppend($p, $r, $s = null)
    {
        if (is_null($s)) $s = $this->Q;
        return preg_match($p, $s) ? preg_replace($p, $r, $s) : $this->implodeOrAppend($r);
    }

    /**
     * 배열이면 implode하고 문자열이면 뒤에 붙입니다.
     *
     * @param string|array $a 배열 또는 문자열
     * @param string $delimiter implode 할 때 쓸 구분자. 기본값은 띄어쓰기
     * @return string 처리된 문자열
     */
    protected function implodeOrAppend($a, $delimiter = ' ')
    {
        return is_array($a) ? implode($delimiter, $a) : $a;
    }    
}