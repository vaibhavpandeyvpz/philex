<?php

if (version_compare(PHP_VERSION, '5.2.0') < 0) {
    die('PHP must be at least of 5.2.0; '.PHP_VERSION.' found.');
} else if (!extension_loaded('json')) {
    die('Extension "json" is not loaded.');
} else if (!extension_loaded('zip')) {
    die('Extension "zip" is not loaded.');
}

if (function_exists('ini_set')) {
    ini_set('max_execution_time', '0');
    ini_set('memory_limit', '-1');
}

class Philex
{
    private static function clean($path)
    {
        static $search = ["\\\\", "\\/", "//", "\\/", "/\\"];
        return str_replace($search, DIRECTORY_SEPARATOR, $path);
    }

    public static function compress(array $source, $destination, $base = null)
    {
        if (empty($base)) {
            $base = getcwd();
        }
        $zip = new ZipArchive();
        $zip->open($destination,  ZipArchive::CREATE);
        foreach ($source as $path) {
            if (is_link($path)) {
                $path = readlink($path);
            }
            $entry = new \SplFileInfo($path);
            if ($entry->isDir()) {
                $name = str_replace($base, '', $entry->getRealPath());
                if (stripos(PHP_OS, 'WIN') === 0) {
                    $name = str_replace('\\', '/', $name);
                }
                $zip->addEmptyDir(ltrim($name, '/').'/');
                try {
                    $children = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                        $entry->getRealPath(), RecursiveDirectoryIterator::SKIP_DOTS
                    ));
                } catch (Exception $ignore) {
                    continue;
                }
                foreach ($children as $child) {
                    /** @var \SplFileInfo $child */
                    $name = str_replace($base, '', $child->getRealPath());
                    if (stripos(PHP_OS, 'WIN') === 0) {
                        $name = str_replace('\\', '/', $name);
                    }
                    if ($child->isDir()) {
                        $zip->addEmptyDir(ltrim($name.'/').'/');
                    } else if ($child->isFile()) {
                        $zip->addFile($child->getPathname(), $name);
                    }
                }
            } else if ($entry->isFile() || $entry->isLink()) {
                $zip->addFile($entry->getPathname(), $entry->getFilename());
            }
        }
        $zip->close();
    }

    public static function copy($path, $into, $mode = 0777)
    {
        $name = basename($path);
        $dest = $into.DIRECTORY_SEPARATOR.$name;
        if (is_file($path)) {
            return copy($path, $dest);
        } elseif (is_link($path)) {
            return symlink(readlink($path), $dest);
        }
        @mkdir($dest, $mode);
        $dir = dir($path);
        while (($entry = $dir->read()) !== false) {
            if (in_array($entry, ['.', '..'])) {
                continue;
            }
            self::copy($path.DIRECTORY_SEPARATOR.$entry, $dest, $mode);
        }
        $dir->close();
        return true;
    }

    public static function files($path)
    {
        $result = [];
        try {
            $children = new FilesystemIterator($path);
            $finfo = function_exists('finfo_open') ? finfo_open() : false;
            foreach ($children as $child) {
                $real = $child->isLink() ? new \SplFileInfo($child->getRealPath()) : $child;
                /** @var \SplFileInfo $child */
                /** @var \SplFileInfo $real */
                $result[] = [
                    'hidden' => strpos($child->getBasename(), '.') === 0,
                    'mime' => $real->isFile()
                        ? ($finfo
                            ? finfo_file($finfo, $real->getPath(), FILEINFO_MIME_TYPE)
                            : 'application/octet-stream'
                        ) : null,
                    'modified' => $child->getMTime(),
                    'name' => $child->getFilename(),
                    'path' => self::clean($child->getPathname()),
                    'permissions' => substr(sprintf('%o', $child->getPerms()), -4),
                    'size' => $child->getSize(),
                    'type' => $real->getType(),
                    '_type' => $child->getType(),
                ];
            }
        } catch (Exception $ignore) {
        }
        usort($result, function ($lhs, $rhs) {
            if ($lhs['type'] === $rhs['type']) {
                return strcmp($lhs['name'], $rhs['name']);
            } else if (($lhs['type'] === 'dir') && ($rhs['type'] === 'file')) {
                return -1;
            } else if (($rhs['type'] === 'dir') && ($lhs['type'] === 'file')) {
                return 1;
            }
            return 0;
        });
        array_unshift($result, [
            'hidden' => false,
            'mime' => 'application/octet-stream',
            'modified' => 0,
            'name' => '..',
            'path' => dirname($path),
            'permissions' => null,
            'size' => 0,
            'type' => 'dir',
        ]);
        return $result;
    }

    public static function uploadlimit()
    {
        $tobytes = function ($size) {
            $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
            $size = preg_replace('/[^0-9\.]/', '', $size);
            return $unit ? round($size * pow(1024, stripos('bkmgtpezy', $unit[0]))) : round($size);
        };
        $max = -1;
        $post_max_size = $tobytes(ini_get('post_max_size'));
        if ($post_max_size > 0) {
            $max = $post_max_size;
        }
        $upload_max_filesize = $tobytes(ini_get('upload_max_filesize'));
        if (($upload_max_filesize > 0) && ($upload_max_filesize < $max)) {
            $max = $upload_max_filesize;
        }
        return $max;
    }

    public static function meta()
    {
        $arch = php_uname('m');
        $cwd = getcwd();
        $hostname = php_uname('n');
        $os = php_uname('s');
        $php = PHP_VERSION;
        $tz = date_default_timezone_get();
        $uploadlimit = self::uploadlimit();
        $user = getenv(stripos(PHP_OS, 'WIN') === 0 ? 'USERNAME' : 'USER');
        $home = getenv(stripos(PHP_OS, 'WIN') === 0 ? 'USERPROFILE' : 'HOME');
        $version = php_uname('r');
        return compact('arch', 'cwd', 'home', 'hostname', 'os', 'php', 'tz', 'uploadlimit', 'user', 'version');
    }

    public static function rimraf($path)
    {
        if (is_file($path) || is_link($path)) {
            return unlink($path);
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                self::rimraf($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        return rmdir($path);
    }
}

$action = empty($_GET['action']) ? null : trim($_GET['action']);
$result = false;

switch ($action) {
    case 'compress':
        $paths = (array) $_POST['path'];
        $destination = $_POST['cwd'].DIRECTORY_SEPARATOR.$_POST['name'];
        Philex::compress($paths, $destination, $_POST['cwd']);
        $result = [];
        break;
    case 'delete':
        $result = [];
        $paths = (array) $_POST['path'];
        foreach ($paths as $path) {
            $success = Philex::rimraf($path);
            $result[] = compact('path', 'success');
        }
        break;
    case 'download':
        $paths = (array) $_POST['path'];
        header('Cache-Control: must-revalidate');
        header('Content-Description: File Transfer');
        header('Expires: 0');
        header('Pragma: public');
        if (count($paths) === 1) {
            $path = $paths[0];
            if (is_link($path)) {
                $path = readlink($path);
            }
            if (is_file($path)) {
                header('Content-Disposition: attachment; filename="'.basename($path).'"');
                header('Content-Length: '.filesize($paths[0]));
                header('Content-Type: application/octet-stream');
                readfile($path);
                exit;
            }
        }
        $temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('download');
        Philex::compress($paths, $temp, $_POST['cwd']);
        header('Content-Disposition: attachment; filename="Files-'.date('YmdHis').'.zip"');
        header('Content-Length: '.filesize($temp));
        header('Content-Type: application/zip');
        readfile($temp);
        unlink($temp);
        exit;
    case 'files':
        $result = [];
        if (is_dir($path = $_POST['path'])) {
            $result = Philex::files($path);
        }
        break;
    case 'meta':
        $result = Philex::meta();
        break;
    case 'new':
        $dest = $_POST['cwd'].DIRECTORY_SEPARATOR.$_POST['name'];
        $type = $_POST['type'];
        $success = ($type === 'file') ? touch($dest) : mkdir($dest, 0777);
        $result = compact('success');
        break;
    case 'paste':
        $result = [];
        $dest = $_POST['cwd'];
        $paths = (array) $_POST['path'];
        foreach ($paths as $path) {
            $success = Philex::copy($path, $dest);
            $result[] = compact('path', 'success');
        }
        if ($_POST['mode'] === 'cut') {
            array_walk($paths, 'Philex::rimraf');
        }
        break;
    case 'upload':
        $result = [];
        $cwd = $_POST['cwd'];
        $uploads = count($_FILES['upload']['name']);
        for ($i = 0; $i < $uploads; $i++) {
            $name = $_FILES['upload']['name'][$i];
            $error = $_FILES['upload']['error'][$i];
            if ($error !== UPLOAD_ERR_OK) {
                $result[] = [
                    'name' => $name,
                    'success' => false,
                ];
                continue;
            }
            $temp = $_FILES['upload']['tmp_name'][$i];
            $dest = $cwd.DIRECTORY_SEPARATOR.$name;
            $success = move_uploaded_file($temp, $dest);
            $result[] = compact('name', 'success');
        }
        break;
    default:
        break;
}

if ($result !== false) {
    header('Content-Type: application/json');
    $options = 0;
    if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
        $options = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
    }
    echo json_encode($result, $options);
    exit;
}

$url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Loading&hellip; - Philex</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootswatch/4.3.1/pulse/bootstrap.min.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.8.0/css/all.css">
    <style>
        a {
            color: black !important;
        }
        .opacity-50 {
            opacity: .5;
        }
        .progress {
            height: 3px;
        }
        .scroll-x-auto {
            overflow-x: auto;
            white-space: nowrap;
        }
        .table-responsive table {
            margin-bottom: 0 !important;
        }
        .table-responsive table thead th,
        .table-responsive table tbody td {
            white-space: nowrap;
        }
        .w-1pt {
            width: 1pt;
        }
    </style>
</head>
<body>
<main id="root">
    <p class="text-center my-3"><i class="fas fa-circle-notch fa-fw fa-spin"></i> Loading&hellip;</p>
</main>
<script crossorigin src="https://unpkg.com/babel-standalone@6/babel.min.js"></script>
<script crossorigin src="https://unpkg.com/react@16/umd/react.production.min.js"></script>
<script crossorigin src="https://unpkg.com/react-dom@16/umd/react-dom.production.min.js"></script>
<script crossorigin src="https://unpkg.com/reactstrap@8.0.0/dist/reactstrap.full.min.js"></script>
<script crossorigin src="https://unpkg.com/prop-types@15.6/prop-types.js"></script>
<script crossorigin src="https://unpkg.com/axios/dist/axios.min.js"></script>
<script crossorigin src="https://unpkg.com/classnames@2.2.6/index.js"></script>
<script crossorigin src="https://unpkg.com/filesize@4.1.1/lib/filesize.js"></script>
<script crossorigin src="https://unpkg.com/moment@2.24.0/min/moment.min.js"></script>
<script>
    const http = axios.create({ baseURL: '<?= $url ?>' })
</script>
<script type="text/babel">
    const { Button, Input, Modal, ModalBody, ModalFooter, ModalHeader } = Reactstrap;

    class CompressDialog extends React.Component {

        constructor(props) {
            super(props);
            this.state = {
                busy: false,
                files: [],
                name: '',
                showing: false,
            };
            this.handleConfirm = this.handleConfirm.bind(this);
            this.handleNameChange = this.handleNameChange.bind(this);
            this.hide = this.hide.bind(this);
            this.show = this.show.bind(this)
        }

        handleConfirm() {
            this.setState({ busy: true }, () => {
                const params = { action: 'compress' };
                const form = new FormData();
                form.append('cwd', this.props.meta.cwd);
                this.state.files.forEach(file => form.append('path[]', file));
                form.append('name', this.state.name);
                http.post('', form, { params })
                    .then(this.props.onCompressed)
                    .then(() => this.setState({
                        busy: false,
                        showing: false,
                    }))
            })
        }

        handleNameChange({ target: { value } }) {
            this.setState({ name: value })
        }

        hide() {
            this.setState({ showing: false })
        }

        render() {
            return <Modal autoFocus={false}
                          backdrop={this.state.busy ? 'static' : true}
                          centered={true}
                          isOpen={this.state.showing}
                          keyboard={!this.state.busy}
                          toggle={this.hide}>
                <ModalHeader toggle={this.state.busy ? null : this.hide}>New</ModalHeader>
                <ModalBody>
                    <p>Enter the name of the <strong>ZIP</strong> archive:</p>
                    <Input autoFocus={true} onChange={this.handleNameChange} />
                </ModalBody>
                <ModalFooter>
                    <Button color="dark" disabled={this.state.busy} onClick={this.hide} outline>Cancel</Button>
                    {' '}
                    <Button color="info" disabled={this.state.busy || (this.state.name === '')} onClick={this.handleConfirm}>
                        <i className={classNames(['fas', { 'fa-file-archive': !this.state.busy, 'fa-circle-notch fa-spin': this.state.busy }, 'mr-1'])} />
                        {' '}
                        {this.state.busy ? `Compressing… (${this.state.progress})%` : 'Compress'}
                    </Button>
                </ModalFooter>
            </Modal>
        }

        show(files) {
            this.setState({
                files,
                name: '',
                showing: true,
            })
        }
    }

    CompressDialog.propTypes = {
        meta: PropTypes.object,
        onCompressed: PropTypes.func,
    }
</script>
<script type="text/babel">
    const { Button, Modal, ModalBody, ModalFooter, ModalHeader } = Reactstrap;

    class ConfirmDeleteDialog extends React.Component {

        constructor(props) {
            super(props);
            this.state = {
                busy: false,
                files: [],
                showing: false,
            };
            this.confirm = this.confirm.bind(this);
            this.handleConfirm = this.handleConfirm.bind(this);
            this.hide = this.hide.bind(this)
        }

        confirm(files) {
            this.setState({
                files,
                showing: true,
            })
        }

        handleConfirm() {
            this.setState({ busy: true }, () => {
                const params = { action: 'delete' };
                const form = new FormData();
                this.state.files.forEach(file => form.append('path[]', file));
                http.post('', form, { params })
                    .then(this.props.onDeleted)
                    .then(() => this.setState({
                        busy: false,
                        showing: false,
                    }))
            })
        }

        hide() {
            this.setState({ showing: false })
        }

        render() {
            return <Modal autoFocus={false}
                          backdrop={this.state.busy ? 'static' : true}
                          centered={true}
                          isOpen={this.state.showing}
                          keyboard={!this.state.busy}
                          toggle={this.hide}>
                <ModalHeader toggle={this.state.busy ? null : this.hide}>Delete</ModalHeader>
                <ModalBody>You are about to delete {this.state.files.length} selected files. This cannot be undone!</ModalBody>
                <ModalFooter>
                    <Button  color="dark" disabled={this.state.busy} onClick={this.hide} outline>Cancel</Button>
                    {' '}
                    <Button autoFocus={true} color="danger" disabled={this.state.busy} onClick={this.handleConfirm}>
                        <i className={classNames(['fas', { 'fa-trash': !this.state.busy, 'fa-circle-notch fa-spin': this.state.busy }, 'mr-1'])} />
                        {' '}
                        {this.state.busy ? 'Deleting…' : 'Confirm'}
                    </Button>
                </ModalFooter>
            </Modal>
        }
    }

    ConfirmDeleteDialog.propTypes = {
        onDeleted: PropTypes.func,
    }
</script>
<script type="text/babel">
    const { CustomInput, Table } = Reactstrap;

    class Files extends React.Component {
        static fileIcon({ hidden, name, size, type }) {
            return classNames({
                'fas fa-folder': (name !== '..') && (type === 'dir'),
                'fas fa-folder-open': (name === '..') && (type === 'dir'),
                'text-warning': (type === 'dir'),
                'far fa-file': (type === 'file') && (size <= 0),
                'fas fa-file': (type === 'file') && (size > 0),
                'fas fa-external-link-square-alt text-info': type === 'link',
                'opacity-50': hidden,
                'fa-fw': true,
            })
        }

        render() {
            return <div>
                <div className="table-responsive">
                    <Table hover>
                        <thead>
                        <tr className="bg-light">
                            <th className="w-1pt" />
                            <th className="w-1pt" />
                            <th className="text-uppercase">Name</th>
                            <th className="text-uppercase">Size</th>
                            <th className="text-uppercase">Permissions</th>
                            <th className="text-uppercase">Mime</th>
                            <th className="text-uppercase">Modified</th>
                        </tr>
                        </thead>
                        {this.props.busy
                            ? null
                            : <tbody>
                            {this.props.files.map((file, i) => (
                                <tr key={`file-${i}`}>
                                    <td>
                                        {file.name !== '..'
                                            ? <CustomInput checked={this.props.selection.some(f => file.path === f.path)}
                                                           id={`file-${i}-selection`}
                                                           onChange={e => this.props.onSelection(file, e.target.checked)}
                                                           type="checkbox" />
                                            : null}
                                    </td>
                                    <td><i className={Files.fileIcon(file)} /></td>
                                    <td><a href="" onClick={e => this.props.onClick(file, e)}>{file.name}</a></td>
                                    <td>{file.name !== '..' ? filesize(file.size) : null}</td>
                                    <td>{file.name !== '..' ? file.permissions : null}</td>
                                    <td>{file.name !== '..' ? file.mime : null}</td>
                                    <td>
                                        {file.name !== '..'
                                            ? moment(file.modified * 1000).format('MMM D \'YY, hh:mm a')
                                            : null}
                                    </td>
                                </tr>
                            ))}
                            </tbody>
                        }
                        <tfoot>
                        <tr className="bg-light">
                            <td />
                            <td />
                            <td colSpan="5"><code>{this.props.meta.cwd}</code></td>
                        </tr>
                        </tfoot>
                    </Table>
                </div>
            </div>
        }
    }

    Files.propTypes = {
        busy: PropTypes.bool,
        files: PropTypes.array,
        meta: PropTypes.object,
        onClick: PropTypes.func,
        onSelection: PropTypes.func,
        selection: PropTypes.array,
    }
</script>
<script type="text/babel">
    const { Button, ButtonGroup, ButtonToolbar, Progress } = Reactstrap;

    class Header extends React.Component {
        render() {
            let decompress = !this.props.busy && (this.props.selection.length === 1);
            if (decompress) {
                decompress = this.props.selection.some(file => {
                    return file.name.endsWith('.bz2')
                        || file.name.endsWith('.gz')
                        || file.name.endsWith('.xz')
                        || file.name.endsWith('.zip')
                })
            }
            return <header className="sticky-top">
                <div className="bg-dark text-muted text-right scroll-x-auto p-1">
                    <small>
                        <strong className="text-white">User:</strong> {this.props.meta.user}@{this.props.meta.hostname}
                        {' - '}
                        <strong className="text-white">System:</strong>
                        {' '}
                        {this.props.meta.os} {this.props.meta.version} ({this.props.meta.arch})
                        {' - '}
                        <strong className="text-white">PHP:</strong> {this.props.meta.php}
                        {' - '}
                        <strong className="text-white">Timezone:</strong> {this.props.meta.tz}
                    </small>
                </div>
                <div className="bg-light scroll-x-auto p-2">
                    <Button color="light" disabled={this.props.busy} onClick={() => this.props.onAction('home')}>
                        <i className="fas fa-home fa-fw" />
                        <span className="d-none d-md-inline ml-1">Home</span>
                    </Button>
                    <Button className="ml-1" color="light" disabled={this.props.busy} onClick={() => this.props.onAction('reload')}>
                        <i className={classNames(['fas', 'fa-sync', { 'fa-spin': this.props.reloading }, 'fa-fw'])} />
                        <span className="d-none d-md-inline ml-1">Reload</span>
                    </Button>
                    <ButtonGroup className="ml-1">
                        <Button color="light" disabled={this.props.busy} onClick={() => this.props.onAction('file')}>
                            <i className="fas fa-file fa-fw" />
                            <span className="d-none d-md-inline">File</span>
                        </Button>
                        <Button color="light" disabled={this.props.busy} onClick={() => this.props.onAction('folder')}>
                            <i className="fas fa-folder fa-fw" />
                            <span className="d-none d-md-inline ml-1">Folder</span>
                        </Button>
                    </ButtonGroup>
                    <ButtonGroup className="ml-1">
                        <Button color="light"
                                disabled={this.props.busy}
                                onClick={() => this.props.onAction('select')}>
                            <i className="far fa-check-square fa-fw" />
                            <span className="d-none d-md-inline ml-1">Select All</span>
                        </Button>
                        <Button color="light"
                                disabled={this.props.busy || (this.props.selection.length <= 0)}
                                onClick={() => this.props.onAction('deselect')}>
                            <i className="far fa-square fa-fw" />
                            <span className="d-none d-md-inline ml-1">Deselect All</span>
                        </Button>
                    </ButtonGroup>
                    <ButtonGroup className="ml-1">
                        <Button color="light"
                                disabled={this.props.busy || (this.props.selection.length <= 0)}
                                onClick={() => this.props.onAction('download')}>
                            <i className="fas fa-download fa-fw" />
                            <span className="d-none d-md-inline ml-1">Download</span>
                        </Button>
                        <Button color="light" disabled={this.props.busy} onClick={() => this.props.onAction('upload')}>
                            <i className="fas fa-upload fa-fw" />
                            <span className="d-none d-md-inline ml-1">Upload</span>
                        </Button>
                    </ButtonGroup>
                    <ButtonGroup className="ml-1">
                        <Button color="light"
                                disabled={this.props.busy || (this.props.selection.length <= 0)}
                                onClick={() => this.props.onAction('cut')}>
                            <i className="fas fa-cut fa-fw" />
                            <span className="d-none d-md-inline">Cut</span>
                        </Button>
                        <Button color="light"
                                disabled={this.props.busy || (this.props.selection.length <= 0)}
                                onClick={() => this.props.onAction('copy')}>
                            <i className="fas fa-copy fa-fw" />
                            <span className="d-none d-md-inline">Copy</span>
                        </Button>
                        <Button color="light"
                                disabled={this.props.busy || (this.props.clipboard.length <= 0)}
                                onClick={() => this.props.onAction('paste')}>
                            <i className="fas fa-paste fa-fw" />
                            <span className="d-none d-md-inline">Paste</span>
                        </Button>
                    </ButtonGroup>
                    <ButtonGroup className="ml-1">
                        <Button color="light"
                                disabled={this.props.busy || (this.props.selection.length !== 1)}
                                onClick={() => this.props.onAction('rename')}>
                            <i className="fas fa-i-cursor fa-fw" />
                            <span className="d-none d-md-inline">Rename</span>
                        </Button>
                        <Button color="light"
                                disabled={this.props.busy
                                || (this.props.selection.length !== 1)
                                || (this.props.selection[0].type !== 'file')}
                                onClick={() => this.props.onAction('edit')}>
                            <i className="fas fa-pencil-alt fa-fw" />
                            <span className="d-none d-md-inline ml-1">Edit</span>
                        </Button>
                        <Button color="danger"
                                disabled={this.props.busy || (this.props.selection.length <= 0)}
                                onClick={() => this.props.onAction('delete')}>
                            <i className="fas fa-trash-alt fa-fw" />
                            <span className="d-none d-md-inline ml-1">Delete</span>
                        </Button>
                    </ButtonGroup>
                    <ButtonGroup className="ml-1">
                        <Button color="light"
                                disabled={this.props.busy || (this.props.selection.length <= 0)}
                                onClick={() => this.props.onAction('compress')}>
                            <i className="fas fa-box fa-fw" />
                            <span className="d-none d-md-inline ml-1">Compress</span>
                        </Button>
                        <Button color="light" disabled={!decompress} onClick={() => this.props.onAction('decompress')}>
                            <i className="fas fa-box-open fa-fw" />
                            <span className="d-none d-md-inline ml-1">Decompress</span>
                        </Button>
                    </ButtonGroup>
                </div>
                <Progress multi>
                    <Progress animated={this.props.busy}
                              bar
                              color={this.props.busy ? 'primary' : 'dark'}
                              striped={!this.props.busy}
                              value="100" />
                </Progress>
            </header>
        }
    }

    Header.propTypes = {
        busy: PropTypes.bool,
        clipboard: PropTypes.array,
        meta: PropTypes.object,
        onAction: PropTypes.function,
        reloading: PropTypes.bool,
        selection: PropTypes.array,
    }
</script>
<script type="text/babel">
    const Loader = () => <p className="text-center my-3">
        <i className="fas fa-circle-notch fa-fw fa-spin" /> Loading&hellip;
    </p>
</script>
<script type="text/babel">
    const { Button, Input, Modal, ModalBody, ModalFooter, ModalHeader } = Reactstrap;

    class NewFileFolderDialog extends React.Component {

        constructor(props) {
            super(props);
            this.state = {
                busy: false,
                name: '',
                type: '',
                showing: false,
            };
            this.handleConfirm = this.handleConfirm.bind(this);
            this.handleNameChange = this.handleNameChange.bind(this);
            this.hide = this.hide.bind(this);
            this.show = this.show.bind(this)
        }

        handleConfirm() {
            this.setState({ busy: true }, () => {
                const params = { action: 'new' };
                const form = new FormData();
                form.append('cwd', this.props.meta.cwd);
                form.append('type', this.state.type);
                form.append('name', this.state.name);
                http.post('', form, { params })
                    .then(this.props.onCreated)
                    .then(() => this.setState({
                        busy: false,
                        showing: false,
                    }))
            })
        }

        handleNameChange({ target: { value } }) {
            this.setState({ name: value })
        }

        hide() {
            this.setState({ showing: false })
        }

        render() {
            return <Modal autoFocus={false}
                          backdrop={this.state.busy ? 'static' : true}
                          centered={true}
                          isOpen={this.state.showing}
                          keyboard={!this.state.busy}
                          toggle={this.hide}>
                <ModalHeader toggle={this.state.busy ? null : this.hide}>New</ModalHeader>
                <ModalBody>
                    <p>Enter the name of the new {this.state.type} below:</p>
                    <Input autoFocus={true} onChange={this.handleNameChange} />
                </ModalBody>
                <ModalFooter>
                    <Button color="dark" disabled={this.state.busy} onClick={this.hide} outline>Cancel</Button>
                    {' '}
                    <Button color="success" disabled={this.state.busy || (this.state.name === '')} onClick={this.handleConfirm}>
                        <i className={classNames(['fas', { 'fa-check': !this.state.busy, 'fa-circle-notch fa-spin': this.state.busy }, 'mr-1'])} />
                        {' '}
                        {this.state.busy ? `Creating… (${this.state.progress})%` : 'Create'}
                    </Button>
                </ModalFooter>
            </Modal>
        }

        show(type) {
            this.setState({
                name: '',
                showing: true,
                type,
            })
        }
    }

    NewFileFolderDialog.propTypes = {
        meta: PropTypes.object,
        onCreated: PropTypes.func,
    }
</script>
<script type="text/babel">
    class Philex extends React.Component {

        constructor(props) {
            super(props);
            this.state = {
                action: null,
                busy: false,
                clipboard: [],
                files: [],
                meta: null,
                reloading: false,
                selection: [],
            };
            this.handleAction = this.handleAction.bind(this);
            this.handleDownload = this.handleDownload.bind(this);
            this.handleFileClick = this.handleFileClick.bind(this);
            this.handleFileSelection = this.handleFileSelection.bind(this);
            this.$RefCompressDialog = React.createRef();
            this.$RefConfirmDeleteDialog = React.createRef();
            this.$RefNewFileFolderDialog = React.createRef();
            this.$RefUploadDialog = React.createRef();
        }

        componentDidMount() {
            http.get('', { params: { action: 'meta' } })
                .then(({ data }) => {
                    document.title = `${data.user}@${data.hostname} - Philex`;
                    this.setState({ meta: data }, () => this.handleAction('reload'))
                })
        }

        handleAction(action) {
            switch (action) {
                case 'compress': {
                    const paths = this.state.selection.map(file => file.path);
                    this.$RefCompressDialog.current.show(paths);
                    break;
                }
                case 'copy':
                case 'cut': {
                    const paths = this.state.selection.map(file => file.path);
                    this.setState({ action, clipboard: paths });
                    break;
                }
                case 'deselect':
                    this.setState({ selection: [] });
                    break;
                case 'delete': {
                    const paths = this.state.selection.map(file => file.path);
                    this.$RefConfirmDeleteDialog.current.confirm(paths);
                    break;
                }
                case 'download': {
                    const paths = this.state.selection.map(file => file.path);
                    this.handleDownload(paths);
                    break;
                }
                case 'file':
                case 'folder':
                    this.$RefNewFileFolderDialog.current.show(action);
                    break;
                case 'home':
                    this.handleFileClick({ path: this.state.meta.home, type: 'dir' });
                    break;
                case 'paste':
                    this.handlePaste();
                    break;
                case 'reload':
                    this.handleFileClick({ path: this.state.meta.cwd, type: 'dir' });
                    break;
                case 'select':
                    this.setState(({ files }) => ({ selection: files.filter(f => f.name !== '..') }));
                    break;
                case 'upload':
                    this.$RefUploadDialog.current.show();
                    break;
                default:
                    break;
            }
        }

        handleDownload(files) {
            const form = document.createElement('form');
            form.setAttribute('action', '<?= $url ?>?action=download');
            form.setAttribute('method', 'post');
            form.setAttribute('target', '_blank');
            const cwd = document.createElement('input');
            cwd.setAttribute('type', 'hidden');
            cwd.setAttribute('name', 'cwd');
            cwd.setAttribute('value', this.state.meta.cwd);
            form.appendChild(cwd);
            files.forEach(file => {
                const path = document.createElement('input');
                path.setAttribute('type', 'hidden');
                path.setAttribute('name', 'path[]');
                path.setAttribute('value', file);
                form.appendChild(path)
            });
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form)
        }

        handleFileClick({ type, path }, e) {
            if (e) {
                e.preventDefault()
            }
            if (type === 'dir') {
                this.setState(({ meta }) => {
                    const changes =  {
                        busy: true,
                        files: [],
                        reloading: true,
                        selection: [],
                    };
                    if (meta.cwd !== path) {
                        changes.meta = Object.assign(meta, { cwd: path })
                    }
                    return changes
                }, () => {
                    const params = { action: 'files' };
                    const form = new FormData();
                    form.set('path', path);
                    http.post('', form, { params })
                        .then(({ data }) => this.setState({ files: data }))
                        .then(() => this.setState({
                            busy: false,
                            reloading: false,
                        }))
                })
            } else {
                this.handleDownload([path])
            }
        }

        handleFileSelection(file, checked) {
            this.setState(({ selection }) => {
                const selected = selection.some(f => file.path === f.path);
                if (checked && !selected) {
                    selection = Array.from(selection);
                    selection.push(file)
                } else if (!checked && selected) {
                    selection = selection.filter(f => file.path !== f.path)
                }
                return { selection }
            })
        }

        handlePaste() {
            const { action, clipboard, meta: { cwd } } = this.state;
            this.setState({ busy: true }, () => {
                const params = { action: 'paste' };
                const form = new FormData();
                form.set('cwd', cwd);
                form.set('mode', action);
                clipboard.forEach(path => form.append('path[]', path));
                http.post('', form, { params })
                    .then(() => {
                        this.setState({
                            action: null,
                            clipboard: [],
                        });
                        this.handleAction('reload')
                    })
            })
        }

        render() {
            return <div>
                {this.state.meta === null
                    ? <Loader />
                    : <div>
                        <CompressDialog meta={this.state.meta}
                                        onCompressed={() => this.handleAction('reload')}
                                        ref={this.$RefCompressDialog} />
                        <ConfirmDeleteDialog onDeleted={() => this.handleAction('reload')}
                                             ref={this.$RefConfirmDeleteDialog} />
                        <NewFileFolderDialog meta={this.state.meta}
                                             onCreated={() => this.handleAction('reload')}
                                             ref={this.$RefNewFileFolderDialog} />
                        <UploadDialog meta={this.state.meta}
                                      onUploaded={() => this.handleAction('reload')}
                                      ref={this.$RefUploadDialog} />
                        <Header busy={this.state.busy}
                                clipboard={this.state.clipboard}
                                meta={this.state.meta}
                                onAction={this.handleAction}
                                reloading={this.state.reloading}
                                selection={this.state.selection} />
                        <Files busy={this.state.busy}
                               files={this.state.files}
                               meta={this.state.meta}
                               onClick={this.handleFileClick}
                               onSelection={this.handleFileSelection}
                               selection={this.state.selection} />
                    </div>
                }
            </div>
        }
    }
</script>
<script type="text/babel">
    const { Button, CustomInput, FormGroup, FormText, Modal, ModalBody, ModalFooter, ModalHeader } = Reactstrap;

    class UploadDialog extends React.Component {

        constructor(props) {
            super(props);
            this.state = {
                busy: false,
                files: [],
                progress: 0,
                showing: false,
            };
            this.handleConfirm = this.handleConfirm.bind(this);
            this.handleFileChange = this.handleFileChange.bind(this);
            this.hide = this.hide.bind(this);
            this.show = this.show.bind(this)
        }

        handleConfirm() {
            this.setState({ busy: true }, () => {
                const headers = { 'Content-Type': 'multipart/form-data' };
                const params = { action: 'upload' };
                const form = new FormData();
                form.append('cwd', this.props.meta.cwd);
                for (let i = 0; i < this.state.files.length; i++ ) {
                    form.append('upload[' + i + ']', this.state.files[i])
                }
                const onUploadProgress = ({ loaded, total }) => {
                    const progress = Math.round((loaded * 100) / total);
                    this.setState({ progress })
                };
                http.post('', form, { headers, onUploadProgress, params })
                    .then(this.props.onUploaded)
                    .then(() => this.setState({
                        busy: false,
                        showing: false,
                    }))
            })
        }

        handleFileChange({ target: { files } }) {
            this.setState({ files })
        }

        hide() {
            this.setState({ showing: false })
        }

        render() {
            return <Modal autoFocus={false}
                          backdrop={this.state.busy ? 'static' : true}
                          centered={true}
                          isOpen={this.state.showing}
                          keyboard={!this.state.busy}
                          toggle={this.hide}>
                <ModalHeader toggle={this.state.busy ? null : this.hide}>Upload</ModalHeader>
                <ModalBody>
                    <p>Please note that the server does not allow uploading of files larger than <strong>{filesize(this.props.meta.uploadlimit)}</strong> in size.</p>
                    <FormGroup className="mb-0">
                        <CustomInput label={this.state.files.length > 0 ? `${this.state.files.length} file(s) selected.` : 'Select one or more files…'}
                                     multiple
                                     onChange={this.handleFileChange}
                                     type="file" />
                        <FormText color="muted">If uploading multiple files, prefer uploading them as a single ZIP archive and decompress later.</FormText>
                    </FormGroup>
                </ModalBody>
                <ModalFooter>
                    <Button color="dark" disabled={this.state.busy} onClick={this.hide} outline>Cancel</Button>
                    {' '}
                    <Button color="primary" disabled={this.state.busy || (this.state.files.length <= 0)} onClick={this.handleConfirm}>
                        <i className={classNames(['fas', { 'fa-upload': !this.state.busy, 'fa-circle-notch fa-spin': this.state.busy }, 'mr-1'])} />
                        {' '}
                        {this.state.busy ? `Uploading… (${this.state.progress})%` : 'Upload'}
                    </Button>
                </ModalFooter>
            </Modal>
        }

        show() {
            this.setState({
                files: [],
                progress: 0,
                showing: true,
            })
        }
    }

    UploadDialog.propTypes = {
        meta: PropTypes.object,
        onUploaded: PropTypes.func,
    }
</script>
<script type="text/babel">
    ReactDOM.render(<Philex />, document.getElementById('root'))
</script>
</body>
</html>
