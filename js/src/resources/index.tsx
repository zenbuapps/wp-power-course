import {
	LineChartOutlined,
	TableOutlined,
	UserOutlined,
	CodeOutlined,
	SettingOutlined,
	ProductOutlined,
	MailOutlined,
} from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { FaPhotoVideo } from 'react-icons/fa'
import { PiStudent } from 'react-icons/pi'

export const resources = [
	{
		name: 'courses',
		list: '/courses',
		edit: '/courses/edit/:id',
		meta: {
			label: __('Course list', 'power-course'),
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
		edit: '/teachers/edit/:id',
		meta: {
			label: __('Instructors', 'power-course'),
			icon: <UserOutlined />,
		},
	},
	{
		name: 'students',
		list: '/students',
		meta: {
			label: __('Students', 'power-course'),
			icon: <PiStudent />,
		},
	},
	{
		name: 'products',
		list: '/products',
		meta: {
			label: __('Course access binding', 'power-course'), // 商品管理
			icon: <ProductOutlined />,
		},
	},
	{
		name: 'shortcodes',
		list: '/shortcodes',
		meta: {
			label: __('Shortcodes', 'power-course'),
			icon: <CodeOutlined />,
		},
	},
	{
		name: 'settings',
		list: '/settings',
		meta: {
			label: __('Settings', 'power-course'),
			icon: <SettingOutlined />,
		},
	},
	{
		name: 'analytics',
		list: '/analytics',
		meta: {
			label: __('Analytics', 'power-course'),
			icon: <LineChartOutlined />,
		},
	},
	{
		name: 'emails',
		list: '/emails',
		edit: '/emails/edit/:id',
		meta: {
			label: __('Email templates', 'power-course'),
			icon: <MailOutlined />,
		},
	},
	{
		name: 'media-library',
		list: '/media-library',
		meta: {
			label: __('Media library', 'power-course'),
			icon: <FaPhotoVideo />,
		},
	},
	{
		name: 'bunny-media-library',
		list: '/bunny-media-library',
		meta: {
			label: __('Bunny media library', 'power-course'),
			icon: <FaPhotoVideo />,
		},
	},
]
