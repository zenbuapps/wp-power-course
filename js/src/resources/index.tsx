import {
	LineChartOutlined,
	TableOutlined,
	UserOutlined,
	CodeOutlined,
	SettingOutlined,
	ProductOutlined,
	MailOutlined,
} from '@ant-design/icons'
import { FaPhotoVideo } from 'react-icons/fa'
import { PiStudent } from 'react-icons/pi'

export const resources = [
	{
		name: 'courses',
		list: '/courses',
		edit: '/courses/edit/:id',
		meta: {
			label: '課程列表',
			icon: <TableOutlined />,
		},
	},
	{
		name: 'chapters',
		list: '/chapters',
		edit: '/chapters/edit/:id',
		meta: {
			hide: true,
		},
	},
	{
		name: 'teachers',
		list: '/teachers',
		meta: {
			label: '講師管理',
			icon: <UserOutlined />,
		},
	},
	{
		name: 'students',
		list: '/students',
		meta: {
			label: '學員管理',
			icon: <PiStudent />,
		},
	},
	{
		name: 'products',
		list: '/products',
		meta: {
			label: '課程權限綁定', // 商品管理
			icon: <ProductOutlined />,
		},
	},
	{
		name: 'shortcodes',
		list: '/shortcodes',
		meta: {
			label: '短代碼',
			icon: <CodeOutlined />,
		},
	},
	{
		name: 'settings',
		list: '/settings',
		meta: {
			label: '設定',
			icon: <SettingOutlined />,
		},
	},
	{
		name: 'analytics',
		list: '/analytics',
		meta: {
			label: '分析',
			icon: <LineChartOutlined />,
		},
	},
	{
		name: 'emails',
		list: '/emails',
		edit: '/emails/edit/:id',
		meta: {
			label: 'Email 模板管理',
			icon: <MailOutlined />,
		},
	},
	{
		name: 'media-library',
		list: '/media-library',
		meta: {
			label: '媒體庫',
			icon: <FaPhotoVideo />,
		},
	},
	{
		name: 'bunny-media-library',
		list: '/bunny-media-library',
		meta: {
			label: 'Bunny 媒體庫',
			icon: <FaPhotoVideo />,
		},
	},
]
