<?php

// +----------------------------------------------------------------------
// | Library for ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2022 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: https://gitee.com/zoujingli/ThinkLibrary
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// +----------------------------------------------------------------------
// | gitee 仓库地址 ：https://gitee.com/zoujingli/ThinkLibrary
// | github 仓库地址 ：https://github.com/zoujingli/ThinkLibrary
// +----------------------------------------------------------------------

declare (strict_types=1);

namespace think\admin\extend;

use Exception;
use think\admin\Library;
use think\admin\model\SystemMenu;
use think\admin\service\SystemService;
use think\helper\Str;

/**
 * 数据库迁移扩展
 * Class PhinxExtend
 * @package think\admin\extend
 */
class PhinxExtend
{
    /**
     * 拷贝文件到指定目录
     * @param string $frdir 源目录
     * @param string $todir 目标目录
     * @param array $files 文件列表
     * @param boolean $force 强制替换
     * @param boolean $remove 删除文件
     * @return boolean
     */
    public static function copyfile(string $frdir, string $todir, array $files = [], bool $force = true, bool $remove = true): bool
    {
        $frdir = trim($frdir, '\\/') . DIRECTORY_SEPARATOR;
        $todir = trim($todir, '\\/') . DIRECTORY_SEPARATOR;
        // 扫描目录文件
        if (empty($files) && file_exists($frdir) && is_dir($frdir)) {
            foreach (scandir($frdir) as $file) if ($file[0] !== '.') {
                is_file($frdir . $file) && ($files[$file] = $file);
            }
        }
        // 复制指定文件
        foreach ($files as $source => $target) {
            if (is_numeric($source)) $source = $target;
            if ($force || !file_exists($todir . $target)) {
                file_exists($todir) || mkdir($todir, 0755, true);
                copy($frdir . $source, $todir . $target);
            }
            $remove && unlink($frdir . $source);
        }
        // 删除源目录
        $remove && static::removeEmptyDirectory($frdir);
        return true;
    }

    /**
     * 移除空目录
     * @param string $path 目录位置
     * @return void
     */
    public static function removeEmptyDirectory(string $path)
    {
        if (file_exists($path) && is_dir($path)) {
            if (count(scandir($path)) === 2 && rmdir($path)) {
                static::removeEmptyDirectory(dirname($path));
            }
        }
    }

    /**
     * 写入系统菜单数据
     * @param array $zdata 菜单数据
     * @param mixed $check 检测条件
     * @return boolean
     */
    public static function write2menu(array $zdata, $check = []): bool
    {
        try { // 检查是否需要写入菜单
            if (!empty($check) && SystemMenu::mk()->where($check)->count() > 0) {
                return false;
            }
        } catch (Exception $exception) {
            return false;
        }
        // 循环写入系统菜单数据
        foreach ($zdata as $one) {
            $pid1 = static::_write2menu($one);
            if (!empty($one['subs'])) foreach ($one['subs'] as $two) {
                $pid2 = static::_write2menu($two, $pid1);
                if (!empty($two['subs'])) foreach ($two['subs'] as $thr) {
                    static::_write2menu($thr, $pid2);
                }
            }
        }
        return true;
    }

    /**
     * 写入系统菜单
     * @param array $menu 菜单数据
     * @param mixed $ppid 上级菜单
     * @return integer|string
     */
    private static function _write2menu(array $menu, $ppid = 0)
    {
        return SystemMenu::mk()->insertGetId([
            'pid'    => $ppid,
            'url'    => empty($menu['url']) ? (empty($menu['node']) ? '#' : $menu['node']) : $menu['url'],
            'sort'   => $menu['sort'] ?? 0,
            'icon'   => $menu['icon'] ?? '',
            'node'   => empty($menu['node']) ? (empty($menu['url']) ? '' : $menu['url']) : $menu['node'],
            'title'  => $menu['name'] ?? ($menu['title'] ?? ''),
            'params' => $menu['params'] ?? '',
            'target' => $menu['target'] ?? '_self',
        ]);
    }

    /**
     * 创建 Phinx 迁移脚本
     * @param array $tables
     * @param string $class
     * @return string[]
     * @throws \Exception
     */
    public static function create2phinx(array $tables = [], string $class = 'InstallTable'): array
    {
        $br = "\r\n";
        $content = static::_build2phinx($tables, true);
        $content = substr($content, strpos($content, "\n") + 1);
        $content = '<?php' . "{$br}{$br}use think\migration\Migrator;{$br}{$br}class {$class} extends Migrator {{$br}{$content}{$br}}{$br}";
        return ['file' => static::_filename($class), 'text' => $content];
    }

    /**
     * 生成 Phinx 迁移脚本
     * @param array $tables 指定数据表
     * @param boolean $source 是否原样返回
     * @return string
     * @throws \Exception
     */
    private static function _build2phinx(array $tables = [], bool $source = false): string
    {
        $br = "\r\n";
        $connect = Library::$sapp->db->connect();
        if ($connect->getConfig('type') !== 'mysql') {
            throw new Exception('只支持 MySql 数据库生成 Phinx 迁移脚本');
        }
        [$config, $database] = [static::_config($tables), $connect->getConfig('database')];
        $content = '<?php' . "{$br}{$br}\t/**{$br}\t * 创建数据库{$br}\t */{$br}\t public function change() {";
        foreach ($config['tables'] as $table) $content .= "{$br}\t\t\$this->_create_{$table}();";
        $content .= "{$br}{$br}\t}{$br}{$br}";

        // 字段类型转换
        $types = [
            'varchar'  => 'string', 'enum' => 'string', 'char' => 'string', // 字符
            'longtext' => 'text', 'tinytext' => 'text', 'mediumtext' => 'text', // 文本
            'tinyblob' => 'binary', 'blob' => 'binary', 'mediumblob' => 'binary', 'longblob' => 'binary', // 文件
            'tinyint'  => 'integer', 'smallint' => 'integer', 'mediumint' => 'integer', 'int' => 'integer', 'bigint' => 'integer', // 整型
        ];
        foreach ($config['tables'] as $table) {

            // 读取数据表 - 备注参数
            $comment = Library::$sapp->db->table('information_schema.TABLES')->where([
                'TABLE_SCHEMA' => $database, 'TABLE_NAME' => $table,
            ])->value('TABLE_COMMENT', '');

            // 读取数据表 - 自动生成结构
            $class = Str::studly($table);
            $content .= <<<CODE
    /**
     * 创建数据对象
     * @class {$class}
     * @table {$table}
     * @return void
     */
    private function _create_{$table}() {

        // 当前数据表
        \$table = '{$table}';

        // 存在则跳过
        if (\$this->hasTable(\$table)) return;

        // 创建数据表
        \$this->table(\$table, [
            'engine' => 'InnoDB', 'collation' => 'utf8mb4_general_ci', 'comment' => '{$comment}',
        ])
CODE;
            foreach (Library::$sapp->db->getFields($table) as $field) {
                if ($field['name'] === 'id') continue;
                $type = $types[$field['type']] ?? $field['type'];
                $data = ['default' => $field['default'], 'null' => empty($field['notnull']), 'comment' => $field['comment'] ?? ''];
                if ($field['type'] === 'enum') {
                    $type = $types[$field['type']] ?? 'string';
                    $data = array_merge(['limit' => 10], $data);
                } elseif (preg_match('/(tinyblob|blob|mediumblob|longblob|varchar|char)\((\d+)\)/', $field['type'], $attr)) {
                    $type = $types[$attr[1]] ?? 'string';
                    $data = array_merge(['limit' => intval($attr[2])], $data);
                } elseif (preg_match('/(tinyint|smallint|mediumint|int|bigint)\((\d+)\)/', $field['type'], $attr)) {
                    $type = $types[$attr[1]] ?? 'integer';
                    $data = array_merge(['limit' => intval($attr[2])], $data);
                    $data['default'] = intval($data['default']);
                } elseif (preg_match('/(float|decimal)\((\d+),(\d+)\)/', $field['type'], $attr)) {
                    $type = $types[$attr[1]] ?? 'decimal';
                    $data = array_merge(['precision' => intval($attr[2]), 'scale' => intval($attr[3])], $data);
                }
                $params = static::array2string($data);
                $content .= "{$br}\t\t->addColumn('{$field["name"]}','{$type}',{$params})";
            }
            // 读取数据表 - 自动生成索引
            $indexs = Library::$sapp->db->query("show index from {$table}");
            foreach ($indexs as $index) {
                if ($index['Key_name'] === 'PRIMARY') continue;
                $params = static::array2string([
                    'name' => "idx_{$index['Table']}_{$index["Column_name"]}",
                ]);
                $content .= "{$br}\t\t->addIndex('{$index["Column_name"]}', {$params})";
            }
            $content .= "{$br}\t\t->save();{$br}{$br}\t\t// 修改主键长度";
            $content .= "{$br}\t\t\$this->table(\$table)->changeColumn('id','integer',['limit'=>20,'identity'=>true]);";
            $content .= "{$br}\t}{$br}{$br}";
        }
        return $source ? $content : highlight_string($content, true);
    }

    /**
     * 生成 Phinx 配置参数
     * @param array $tables 数据表结构
     * @param array $backup 数据表备份
     * @return array
     */
    private static function _config(array $tables = [], array $backup = []): array
    {
        if (empty($tables)) [$tables] = SystemService::getTables();
        $tables = array_diff(array_unique($tables), Library::$sapp->config->get('phinx.ignore', []), ['migrations']);
        $backup = array_unique(array_intersect($tables, array_merge($backup, Library::$sapp->config->get('phinx.backup', []))));
        return ['tables' => $tables, 'backup' => $backup];
    }

    /**
     * 数组转代码
     * @param array $data
     * @return string
     */
    public static function array2string(array $data): string
    {
        return preg_replace(['#\s+#', '#, \)$#', '#^array \( #'], [' ', ' ]', '[ ',], var_export($data, true));
    }

    /**
     * 生成脚本名称
     * @param string $class 脚本类名
     * @return string
     */
    private static function _filename(string $class): string
    {
        if (count($files = glob(with_path('database/migrations/*.php'))) > 0) {
            $verint = intval(substr(basename(end($files)), 8, 6));
            $version = str_pad(strval($verint + 1), 6, '0', STR_PAD_LEFT);
        } else {
            $version = '000001';
        }
        return date("Ymd{$version}_") . Str::snake($class) . '.php';
    }

    /**
     * 创建 Phinx 安装脚本
     * @param array $tables
     * @param string $class
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function create2package(array $tables = [], string $class = 'InstallPackage'): array
    {
        // 处理菜单数据
        [$menuData, $menuList] = [[], SystemMenu::mk()->where(['status' => 1])->order('sort desc,id asc')->select()->toArray()];
        foreach (DataExtend::arr2tree($menuList) as $sub1) {
            $one = ['name' => $sub1['title'], 'icon' => $sub1['icon'], 'url' => $sub1['url'], 'node' => $sub1['node'], 'params' => $sub1['params'], 'subs' => []];
            if (!empty($sub1['sub'])) foreach ($sub1['sub'] as $sub2) {
                $two = ['name' => $sub2['title'], 'icon' => $sub2['icon'], 'url' => $sub2['url'], 'node' => $sub2['node'], 'params' => $sub2['params'], 'subs' => []];
                if (!empty($sub2['sub'])) foreach ($sub2['sub'] as $sub3) {
                    $two['subs'][] = ['name' => $sub3['title'], 'url' => $sub3['url'], 'node' => $sub3['node'], 'icon' => $sub3['icon'], 'params' => $sub3['params']];
                }
                if (empty($two['subs'])) unset($two['subs']);
                $one['subs'][] = $two;
            }
            if (empty($one['subs'])) unset($one['subs']);
            $menuData[] = $one;
        }
        // 读取配置并备份数据
        [$extra, $config] = [[], static::_config([], $tables)];
        if (count($config['backup']) > 0) foreach ($config['backup'] as $table) {
            if (($db = Library::$sapp->db->table($table))->count() > 0) {
                $extra[$table] = CodeExtend::enzip($db->select()->toJson());
            }
        }
        // 生成迁移脚本
        $serach = ['__CLASS__', '__MENU_ZIPS__', '__DATA_JSON__'];
        $content = file_get_contents(dirname(__DIR__) . '/service/bin/package.stud');
        $replace = [$class, CodeExtend::enzip($menuData), json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)];
        return ['file' => static::_filename($class), 'text' => str_replace($serach, $replace, $content)];
    }
}