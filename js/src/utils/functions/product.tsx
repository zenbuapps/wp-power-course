import defaultImage from '@/assets/images/defaultImage.jpg'
import { BUNDLE_TYPE_OPTIONS } from '@/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/utils'
import { TProduct as TProductRestApi } from '@/types/wcRestApi'
import { TProduct as TProductStoreApi } from '@/types/wcStoreApi'

export const getProductImageSrc = (
	product: TProductStoreApi | TProductRestApi
) => {
	const images = product?.images ?? []
	const image = images[0] ?? {}
	const imageSrc = image?.src ?? defaultImage
	return imageSrc
}

export const getBundleType = (bundleType: string) => {
	return BUNDLE_TYPE_OPTIONS.find((option) => option.value === bundleType)
}
