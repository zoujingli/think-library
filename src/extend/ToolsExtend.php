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

use think\admin\Exception;
use think\admin\Library;
use think\helper\Str;

/**
 * 扩展工具包
 * Class DataExtend
 * @package think\admin\extend
 */
class ToolsExtend
{

    /**
     * 拷贝文件到指定目录
     * @param string $frdir 源目录
     * @param string $todir 目标目录
     * @param array $files 文件目录
     * @param boolean $force 强制替换
     * @param boolean $remove 移除文件
     * @return boolean
     */
    public static function copyfile(string $frdir, string $todir, array $files = [], bool $force = true, bool $remove = true): bool
    {
        $frdir = trim($frdir, '\\/') . DIRECTORY_SEPARATOR;
        $todir = trim($todir, '\\/') . DIRECTORY_SEPARATOR;
        file_exists($todir) || mkdir($todir, 0755, true);
        // 扫描目录文件
        if (empty($files) && file_exists($frdir) && is_dir($frdir)) {
            foreach (($files = scandir($frdir)) as $key => $file) {
                if (strpos($file, '.') === 0 || is_dir($frdir . $file)) {
                    unset($files[$key]);
                }
            }
        }
        // 移动指定文件
        foreach ($files as $file) {
            if ($force || !file_exists($todir . $file)) {
                copy($frdir . $file, $todir . $file);
            }
            $remove && unlink($frdir . $file);
        }
        // 移除空的目录
        if ($remove && file_exists($frdir) && is_dir($frdir)) {
            count(glob("{$frdir}/*")) <= 0 && rmdir($frdir);
        }
        return true;
    }

    /**
     * 文本转为UTF8编码
     * @param string $content
     * @return string
     */
    public static function text2utf8(string $content): string
    {
        return mb_convert_encoding($content, 'UTF-8', mb_detect_encoding($content, [
            'ASCII', 'UTF-8', 'GB2312', 'GBK', 'BIG5',
        ]));
    }

    /**
     * 生成 Phinx 的迁移脚本
     * @param null|array $tables
     * @return string
     * @throws \think\admin\Exception
     */
    public static function mysql2phinx(?array $tables = null): string
    {
        $connect = Library::$sapp->db->connect();
        if ($connect->getConfig('type') !== 'mysql') {
            throw new Exception('只支持 MySql 数据库生成 Phinx 迁移脚本');
        }
        $tables = $tables ?: Library::$sapp->db->getTables();

        $content = "<?php\n\n\t/**\n\t * 创建数据库\n\t */\n\t public function change() {";
        foreach ($tables as $table) $content .= "\n\t\t\$this->_{$table}_change();";
        $content .= "\n\n\t}\n\n";

        foreach ($tables as $table) {

            // 读取数据表 - 备注参数
            $map = ['TABLE_SCHEMA' => $connect->getConfig('database'), 'TABLE_NAME' => $table];
            $comment = Library::$sapp->db->table('information_schema.TABLES')->where($map)->value('TABLE_COMMENT', '');

            // 读取数据表 - 索引数据
            $indexs = Library::$sapp->db->query("show index from {$table}");

            $class = Str::studly($table);
            $content .= <<<CODE
    
    /**
     * 创建数据对象
     * @class {$class}
     * @table {$table}
     * @return void
     */
    private function _{$table}_change() {
        
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
                $type = $field['type'];
                $data = ['default' => $field['default'], 'comment' => $field['comment'] ?? ''];
                if (preg_match('/(longtext)/', $field['type'])) {
                    $type = 'text';
                } elseif (preg_match('/(varchar|char)\((\d+)\)/', $field['type'], $attr)) {
                    $type = 'string';
                    $data = array_merge(['limit' => intval($attr[2])], $data);
                } elseif (preg_match('/(bigint|tinyint|int)\((\d+)\)/', $field['type'], $attr)) {
                    $type = 'integer';
                    $data = array_merge(['limit' => intval($attr[2])], $data);
                    $data['default'] = intval($data['default']);
                } elseif (preg_match('/decimal\((\d+),(\d+)\)/', $field['type'], $attr)) {
                    $type = 'decimal';
                    $data = array_merge(['precision' => intval($attr[1]), 'scale' => intval($attr[2])], $data);
                }
                $params = static::array2string($data);
                $content .= "\n\t\t->addColumn('{$field["name"]}', '{$type}', {$params})";
            }
            // 自动生成索引
            foreach ($indexs as $index) {
                if ($index['Key_name'] === 'PRIMARY') continue;
                $params = static::array2string([
                    'name' => "idx_{$index['Table']}_{$index["Column_name"]}",
                ]);
                $content .= "\n\t\t->addIndex('{$index["Column_name"]}', {$params})";
            }
            $content .= "\n\t\t->save();\n\n\t}\n\n";
        }
        return highlight_string($content, true);
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
}