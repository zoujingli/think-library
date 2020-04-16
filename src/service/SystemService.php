<?php

// +----------------------------------------------------------------------
// | ThinkAdmin
// +----------------------------------------------------------------------
// | 版权所有 2014~2020 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: https://gitee.com/zoujingli/ThinkLibrary
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// +----------------------------------------------------------------------
// | gitee 代码仓库：https://gitee.com/zoujingli/ThinkLibrary
// | github 代码仓库：https://github.com/zoujingli/ThinkLibrary
// +----------------------------------------------------------------------

namespace think\admin\service;

use think\admin\Service;
use think\db\Query;

/**
 * 系统参数管理服务
 * Class SystemService
 * @package think\admin\service
 */
class SystemService extends Service
{

    /**
     * 配置数据缓存
     * @var array
     */
    protected $data = [];

    /**
     * 设置配置数据
     * @param string $name 配置名称
     * @param string $value 配置内容
     * @return static
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function set($name, $value = '')
    {
        [$type, $field] = $this->parse($name);
        if (is_array($value)) {
            foreach ($value as $k => $v) $this->set("{$field}.{$k}", $v);
        } else {
            $this->data = [];
            $data = ['name' => $field, 'value' => $value, 'type' => $type];
            $this->save('SystemConfig', $data, 'name', ['type' => $type]);
        }
        return $this;
    }

    /**
     * 读取配置数据
     * @param string $name
     * @return array|mixed|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function get($name)
    {
        [$type, $field, $outer] = $this->parse($name);
        if (empty($this->data)) foreach ($this->app->db->name('SystemConfig')->select() as $vo) {
            $this->data[$vo['type']][$vo['name']] = $vo['value'];
        }
        if (empty($name)) {
            return empty($this->data[$type]) ? [] : ($outer === 'raw' ? $this->data[$type] : array_map(function ($value) {
                return htmlspecialchars($value);
            }, $this->data[$type]));
        } else {
            if (isset($this->data[$type]) && isset($this->data[$type][$field])) {
                return $outer === 'raw' ? $this->data[$type][$field] : htmlspecialchars($this->data[$type][$field]);
            } else return '';
        }
    }

    /**
     * 数据增量保存
     * @param Query|string $dbQuery 数据查询对象
     * @param array $data 需要保存或更新的数据
     * @param string $key 条件主键限制
     * @param array $where 其它的where条件
     * @return boolean|integer
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function save($dbQuery, $data, $key = 'id', $where = [])
    {
        $db = is_string($dbQuery) ? $this->app->db->name($dbQuery) : $dbQuery;
        [$table, $value] = [$db->getTable(), isset($data[$key]) ? $data[$key] : null];
        $map = isset($where[$key]) ? [] : (is_string($value) ? [[$key, 'in', explode(',', $value)]] : [$key => $value]);
        if (is_array($info = $this->app->db->table($table)->master()->where($where)->where($map)->find()) && !empty($info)) {
            if ($this->app->db->table($table)->strict(false)->where($where)->where($map)->update($data) !== false) {
                return isset($info[$key]) ? $info[$key] : true;
            } else {
                return false;
            }
        } else {
            return $this->app->db->table($table)->strict(false)->insertGetId($data);
        }
    }

    /**
     * 解析缓存名称
     * @param string $rule 配置名称
     * @param string $type 配置类型
     * @return array
     */
    private function parse($rule, $type = 'base')
    {
        if (stripos($rule, '.') !== false) {
            [$type, $rule] = explode('.', $rule);
        }
        [$field, $outer] = explode('|', "{$rule}|");
        return [$type, $field, strtolower($outer)];
    }

    /**
     * 保存数据内容
     * @param string $name
     * @param mixed $value
     * @return boolean
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setData($name, $value)
    {
        $data = ['name' => $name, 'value' => serialize($value)];
        return $this->save('SystemData', $data, 'name');
    }

    /**
     * 读取数据内容
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function getData($name, $default = [])
    {
        try {
            $value = $this->app->db->name('SystemData')->where(['name' => $name])->value('value', null);
            return is_null($value) ? $default : unserialize($value);
        } catch (\Exception $e) {
            return $default;
        }
    }

    /**
     * 写入系统日志
     * @param string $action
     * @param string $content
     * @return integer
     */
    public function setOplog($action, $content)
    {
        return $this->app->db->name('SystemOplog')->insert([
            'node'     => NodeService::instance()->getCurrent(),
            'action'   => $action, 'content' => $content,
            'geoip'    => $this->app->request->isCli() ? '127.0.0.1' : $this->app->request->ip(),
            'username' => $this->app->request->isCli() ? 'cli' : $this->app->session->get('user.username', ''),
        ]);
    }

    /**
     * 打印输出数据到文件
     * @param mixed $data 输出的数据
     * @param boolean $new 强制替换文件
     * @param string|null $file 文件名称
     */
    public function putDebug($data, $new = false, $file = null)
    {
        if (is_null($file)) $file = $this->app->getRootPath() . 'runtime' . DIRECTORY_SEPARATOR . date('Ymd') . '.log';
        $str = (is_string($data) ? $data : ((is_array($data) || is_object($data)) ? print_r($data, true) : var_export($data, true))) . PHP_EOL;
        $new ? file_put_contents($file, $str) : file_put_contents($file, $str, FILE_APPEND);
    }

    /**
     * 判断运行环境
     * @param string $type 运行模式（dev|demo|local）
     * @return boolean
     */
    public function checkRunMode($type = 'dev')
    {
        $domain = $this->app->request->host(true);
        $isDemo = is_numeric(stripos($domain, 'thinkadmin.top'));
        $isLocal = in_array($domain, ['127.0.0.1', 'localhost']);
        if ($type === 'dev') return $isLocal || $isDemo;
        if ($type === 'demo') return $isDemo;
        if ($type === 'local') return $isLocal;
        return true;
    }

    /**
     * 设置运行环境模式
     * @param null|boolean $state
     * @return boolean
     */
    public function productMode($state = null)
    {
        if (is_string($state)) {
            $this->setRuntime([], $state ? 'product' : 'developoer');
            return !$this->app->debug(empty($state))->isDebug();
        }
        return !$this->app->debug($this->getRuntime('app_run') !== 'product')->isDebug();
    }

    /**
     * 设置实时运行配置
     * @param array|null $map 应用映射
     * @param string|null $run 支持模式
     * @return boolean
     */
    public function setRuntime($map = [], $run = null)
    {
        $data = $this->getRuntime();
        if (is_array($map)) foreach ($data['app_map'] as $kk => $vv) foreach ($map as $oo) {
            if ($oo === $vv) unset($data['app_map'][$kk]);
        }
        $file = "{$this->app->getRootPath()}runtime/config.json";
        $data['app_run'] = is_null($run) ? $data['app_run'] : $run;
        $data['app_map'] = is_null($map) ? [] : array_merge($data['app_map'], $map);
        return file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * 获取实时运行配置
     * @param null|string $key
     * @return array
     */
    public function getRuntime($key = null)
    {
        $file = "{$this->app->getRootPath()}runtime/config.json";
        $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
        if (empty($data) || !is_array($data)) $data = [];
        if (empty($data['app_map']) || !is_array($data['app_map'])) $data['app_map'] = [];
        if (empty($data['app_run']) || !is_string($data['app_run'])) $data['app_run'] = 'developer';
        return is_null($key) ? $data : (isset($data[$key]) ? $data[$key] : null);
    }

    /**
     * 绑定应用实时配置
     */
    public function bindRuntime()
    {
        // 动态绑定应用映射
        $data = $this->getRuntime();
        if (!empty($data['app_map'])) {
            $maps = $this->app->config->get('app.app_map', []);
            $this->app->config->set(['app_map' => array_merge($maps, $data['app_map'])], 'app');
        }
        // 动态设置当前运行模式
        $this->app->debug($data['app_run'] !== 'product');
    }

    /**
     * 生成最短URL地址
     * @param string $url 路由地址
     * @param array $vars 变量
     * @param boolean|string $suffix 后缀
     * @param boolean|string $domain 域名
     * @return string
     */
    public function sysuri($url = '', array $vars = [], $suffix = false, $domain = false)
    {
        $location = $this->app->route->buildUrl($url, $vars)->suffix($suffix)->domain($domain)->build();
        [$d1, $d2, $d3] = [$this->app->config->get('app.default_app'), $this->app->config->get('route.default_controller'), $this->app->config->get('route.default_action'),];
        return preg_replace(["|^/{$d1}/{$d2}/{$d3}(\.html)?$|i", "|/{$d2}/{$d3}(\.html)?$|i", "|/{$d3}(\.html)?$|i"], '', $location);
    }

}